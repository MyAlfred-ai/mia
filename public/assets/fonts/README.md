# Polices auto-hébergées

Aucune police n'est chargée depuis un CDN : l'app s'installe en PWA et tourne
derrière des réseaux fermés (`myllm` est `internal: true` en prod). Tout est
servi depuis ce dossier.

Les deux rôles sont déclarés dans `public/assets/css/main.css` :

| Jeton CSS      | Police      | Usage                                                        |
|----------------|-------------|--------------------------------------------------------------|
| `--font`       | Lexend      | **Toute** l'interface (`body`, champs, boutons, titres…)      |
| `--font-title` | Congenial   | Le **nom du produit** seul (`.logo-text`, en-tête mobile, `h1` de connexion) |

## Lexend — versionnée

`lexend-latin.woff2` + `lexend-latin-ext.woff2`, fichiers variables (100→900),
sous SIL Open Font License 1.1 (`LEXEND-OFL.txt`). Rien à faire.

Les deux sous-ensembles portent un `unicode-range` : `latin-ext` n'est
téléchargé que si la page contient un caractère qui l'exige.

## Congenial — À DÉPOSER À LA MAIN

Congenial (Laura Worthington Design) est une police **commerciale** : elle n'est
pas — et ne doit pas être — versionnée (`.gitignore` refuse
`public/assets/fonts/congenial-*`).

Après achat de la licence **webfont**, déposer ici les deux fichiers sous ces
noms exacts :

```
public/assets/fonts/congenial-regular.woff2   (400)
public/assets/fonts/congenial-bold.woff2      (700)
```

Aucune modification du CSS n'est nécessaire : les `@font-face` les attendent
déjà. En Docker, le dossier fait partie de l'image → déposer les fichiers puis
reconstruire (`docker compose build app`), ou les monter en volume.

**Tant qu'ils sont absents**, la requête échoue et le nom du produit retombe
sur Lexend (`--font-title` liste les deux). Rien ne casse et aucun texte n'est
invisible : `font-display: swap` n'attend jamais une police pour peindre.
