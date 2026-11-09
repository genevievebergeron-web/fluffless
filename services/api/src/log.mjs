// Journal JSON d'une ligne. Les appelants passent seulement des champs non personnels :
// jamais d'URL complète (la chaîne de requête porte le site), jamais d'en-tête de licence, jamais d'IP,
// jamais de courriel ni de texte de signalement.
export function createLogger(write = (line) => process.stdout.write(`${line}\n`)) {
	return (level, event, fields = {}) => {
		write(JSON.stringify({ t: new Date().toISOString(), level, event, ...fields }));
	};
}
