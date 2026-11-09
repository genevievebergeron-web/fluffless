// Comparaison de versions à la manière de semver : 1.2 vaut 1.2.0, et une préversion
// (0.0.1-test) précède la version finale (0.0.1). Sans dépendance : trente lignes
// suffisent là où une bibliothèque en ajouterait des milliers.
const VERSION = /^(\d{1,6})\.(\d{1,6})(?:\.(\d{1,6}))?(?:-([0-9A-Za-z.-]{1,40}))?$/;

export function parseVersion(value) {
	const match = VERSION.exec(String(value ?? ''));
	if (!match) {
		return null;
	}
	return {
		core: [Number(match[1]), Number(match[2]), Number(match[3] ?? 0)],
		pre: match[4] ? match[4].split('.') : [],
	};
}

function compareIdentifiers(a, b) {
	const numA = /^\d+$/.test(a);
	const numB = /^\d+$/.test(b);
	if (numA && numB) {
		return Math.sign(Number(a) - Number(b));
	}
	if (numA !== numB) {
		return numA ? -1 : 1;
	}
	return a < b ? -1 : a > b ? 1 : 0;
}

export function compareVersions(left, right) {
	const a = parseVersion(left);
	const b = parseVersion(right);
	if (!a || !b) {
		throw new Error(`Version invalide : ${a ? right : left}`);
	}
	for (let i = 0; i < 3; i++) {
		if (a.core[i] !== b.core[i]) {
			return Math.sign(a.core[i] - b.core[i]);
		}
	}
	if (a.pre.length === 0 || b.pre.length === 0) {
		return Math.sign(b.pre.length - a.pre.length);
	}
	for (let i = 0; i < Math.min(a.pre.length, b.pre.length); i++) {
		const order = compareIdentifiers(a.pre[i], b.pre[i]);
		if (order !== 0) {
			return order;
		}
	}
	return Math.sign(a.pre.length - b.pre.length);
}
