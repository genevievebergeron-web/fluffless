// Limiteur à fenêtre fixe, en mémoire seulement : les adresses IP ne touchent jamais
// la base (Loi 25). Un redémarrage remet les compteurs à zéro, ce qui est acceptable.
// La table est bornée pour qu'un balayage d'adresses ne fasse pas enfler la mémoire.
export function createRateLimiter({ limit, windowMs, maxKeys = 10_000, now = Date.now }) {
	const hits = new Map();

	function sweep(time) {
		for (const [key, entry] of hits) {
			if (time >= entry.resetAt) {
				hits.delete(key);
			}
		}
	}

	return {
		/** @returns {{ allowed: boolean, retryAfterSeconds: number }} */
		hit(key) {
			const time = now();
			let entry = hits.get(key);
			if (!entry || time >= entry.resetAt) {
				if (!entry && hits.size >= maxKeys) {
					sweep(time);
				}
				if (!entry && hits.size >= maxKeys) {
					// Table pleine de fenêtres encore actives : on refuse plutôt que d'oublier un compteur.
					return { allowed: false, retryAfterSeconds: Math.ceil(windowMs / 1000) };
				}
				entry = { count: 0, resetAt: time + windowMs };
				hits.set(key, entry);
			}
			entry.count++;
			return {
				allowed: entry.count <= limit,
				retryAfterSeconds: Math.max(1, Math.ceil((entry.resetAt - time) / 1000)),
			};
		},
		/** Vrai si la clé a une fenêtre en cours, sans la compter. */
		active(key) {
			const entry = hits.get(key);
			return entry !== undefined && now() < entry.resetAt;
		},
		get size() {
			return hits.size;
		},
	};
}

// Même fenêtre fixe, plus un verrou qui double à chaque dépassement (15 min, 30, 60…,
// plafonné à maxLockMs). Les dépassements s'oublient après maxLockMs sans nouvel écart.
export function createLockout({ limit, windowMs, maxLockMs, maxKeys = 10_000, now = Date.now }) {
	const entries = new Map();

	function sweep(time) {
		for (const [key, entry] of entries) {
			if (time >= entry.resetAt && time >= entry.lockedUntil && time - entry.lastStrikeAt > maxLockMs) {
				entries.delete(key);
			}
		}
	}

	return {
		/** @returns {{ allowed: boolean, retryAfterSeconds: number }} */
		hit(key) {
			const time = now();
			let entry = entries.get(key);
			if (!entry) {
				if (entries.size >= maxKeys) {
					sweep(time);
				}
				if (entries.size >= maxKeys) {
					return { allowed: false, retryAfterSeconds: Math.ceil(windowMs / 1000) };
				}
				entry = { count: 0, resetAt: time + windowMs, lockedUntil: 0, strikes: 0, lastStrikeAt: -Infinity };
				entries.set(key, entry);
			}
			if (time < entry.lockedUntil) {
				return { allowed: false, retryAfterSeconds: Math.ceil((entry.lockedUntil - time) / 1000) };
			}
			if (time - entry.lastStrikeAt > maxLockMs) {
				entry.strikes = 0;
			}
			if (time >= entry.resetAt) {
				entry.count = 0;
				entry.resetAt = time + windowMs;
			}
			entry.count++;
			if (entry.count <= limit) {
				return { allowed: true, retryAfterSeconds: 0 };
			}
			entry.strikes++;
			entry.lastStrikeAt = time;
			entry.lockedUntil = time + Math.min(windowMs * 2 ** (entry.strikes - 1), maxLockMs);
			entry.count = 0;
			entry.resetAt = entry.lockedUntil;
			return { allowed: false, retryAfterSeconds: Math.ceil((entry.lockedUntil - time) / 1000) };
		},
		get size() {
			return entries.size;
		},
	};
}
