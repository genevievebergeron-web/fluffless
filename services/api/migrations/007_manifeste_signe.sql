-- Manifeste signé (C10) : {slug, version, sha256} en JSON canonique, signé ed25519 à la publication,
-- servi par GET /v1/update. Le site vérifie la signature du manifeste avant toute installation.
-- Les versions publiées avant cette migration n'ont pas de manifeste : à republier sous un nouveau
-- numéro (aucune version n'est en ligne au 6 octobre 2026).
alter table releases
	add column manifest text not null default '',
	add column manifest_signature text not null default '';
