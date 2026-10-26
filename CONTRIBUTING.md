# Contribuer à MIA

## Branches

- `main` : versions publiées, une étiquette `vX.Y.Z` par version.
- `develop` : intégration. Les pull requests visent `develop`.
- Branches de travail : `feat/<sujet>`, `fix/<sujet>`, `docs/<sujet>`.

Les deux branches protégées exigent une relecture et une CI verte.

## Commits

- Messages au format Conventional Commits : `feat(zone): …`, `fix(zone): …`, `docs: …`.
- Chaque commit est signé selon le Developer Certificate of Origin : `git commit -s`.

## Versions

Versionnage sémantique `X.Y.Z`. Le numéro de version a une seule source dans le dépôt, et l'étiquette Git doit lui correspondre.

## Données dans le code

N'utilisez que des données fictives dans le code, les tests, la documentation et les prompts : domaines `example.test`, adresses `192.0.2.0/24`, entreprises « Acme » ou « Globex ». Le CI refuse les adresses IP réelles et les fichiers de secrets.

## Sécurité

Voir [SECURITY.md](SECURITY.md).
