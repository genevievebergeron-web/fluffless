import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const MIGRATIONS_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', 'migrations');
const LOCK_ID = 7_274_001; // Empêche deux instances de migrer en même temps.
const RETENTION = "interval '12 months'";

// Chaque fichier .sql s'applique une fois, dans sa propre transaction, par ordre de nom.
// Une connexion réservée garde le verrou consultatif sur la même session du début à la fin.
export async function migrate(sql, dir = MIGRATIONS_DIR) {
	const conn = await sql.reserve();
	const applied = [];
	try {
		await conn`select pg_advisory_lock(${LOCK_ID})`;
		await conn`create table if not exists schema_migrations (name text primary key, applied_at timestamptz not null default now())`;
		const done = new Set((await conn`select name from schema_migrations`).map((row) => row.name));
		const files = readdirSync(dir).filter((file) => file.endsWith('.sql')).sort();
		for (const file of files) {
			if (done.has(file)) {
				continue;
			}
			await conn.unsafe('begin');
			try {
				await conn.unsafe(readFileSync(join(dir, file), 'utf8'));
				await conn`insert into schema_migrations (name) values (${file})`;
				await conn.unsafe('commit');
			} catch (error) {
				await conn.unsafe('rollback');
				throw new Error(`Migration ${file} échouée : ${error.message}`);
			}
			applied.push(file);
		}
	} finally {
		await conn`select pg_advisory_unlock(${LOCK_ID})`.catch(() => {});
		conn.release();
	}
	return applied;
}

// Loi 25 : un signalement réglé depuis douze mois n'a plus de raison d'être conservé.
export async function purgeResolvedReports(sql) {
	const rows = await sql.unsafe(`delete from reports where status = 'réglé' and resolved_at < now() - ${RETENTION} returning id`);
	return rows.length;
}

// Loi 25 : un événement Stripe ne sert qu'à la déduplication (Stripe réessaie trois jours);
// 90 jours laissent le temps d'enquêter. Un achat réglé reste une pièce comptable sept ans
// (six ans après la fin de l'exercice, Revenu Québec). Un remboursement en attente reste.
export async function purgeStripeRecords(sql) {
	const events = await sql`delete from stripe_events where received_at < now() - interval '90 days' returning id`;
	const purchases = await sql`
		delete from pack_purchases where outcome <> 'refund_pending' and created_at < now() - interval '7 years'
		returning checkout_session_id`;
	const licencePurchases = await sql`
		delete from licence_purchases where outcome <> 'refund_pending' and created_at < now() - interval '7 years'
		returning checkout_session_id`;
	return { events: events.length, purchases: purchases.length, licence_purchases: licencePurchases.length };
}

// Places de licence : un site de développement sans contact depuis 90 jours sort du compte, une
// libération ne sert qu'au plafond sur 30 jours. Un signalement part 12 mois après le dernier de
// ces moments : sa création, son examen, le rétablissement de la licence. Celui qui a mené à un
// avis ou à une révocation reste tant que l'avis ou la révocation tient.
export async function purgeLicencePlaces(sql) {
	const devSites = await sql`delete from licence_dev_sites where last_seen_at < now() - interval '90 days' returning 1`;
	const releases = await sql`delete from licence_site_releases where released_at < now() - interval '30 days' returning 1`;
	const flags = await sql`
		delete from licence_flags f using licences l
		where l.id = f.licence_id
			and greatest(f.created_at, f.reviewed_at, l.restored_at) < now() - interval '12 months'
			and not (f.outcome in ('notice', 'revoked') and (l.notice_at is not null or l.revoked_at is not null))
		returning 1`;
	return { dev_sites: devSites.length, releases: releases.length, flags: flags.length };
}

// Loi 25 (politique de confidentialité) : une licence échue depuis 12 mois s'efface, avec son
// courriel, ses sites et ses réclamations de packs. La date de départ est la fin, ou le
// rétablissement s'il est plus tardif. Les pièces d'achat restent 7 ans, sans lien vers elle.
// Une licence sans fin ne s'efface que sur demande (delete-licence).
export async function purgeExpiredLicences(sql) {
	const rows = await sql.begin(async (tx) => {
		const expired = tx`
			select id from licences
			where expires_at is not null and greatest(expires_at, restored_at) < now() - interval '12 months'`;
		await tx`delete from pack_claims where licence_id in (${expired})`;
		return tx`delete from licences where id in (${expired}) returning id`;
	});
	return { licences: rows.length };
}
