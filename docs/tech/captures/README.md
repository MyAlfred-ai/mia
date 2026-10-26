# Captures de la documentation utilisateur

Les images de `docs/user/images/` qui montrent l'écran — aujourd'hui `ecran-principal.png`, de la
section « Se repérer dans MIA » de [Premiers pas](../../user/premiers-pas.md) — se refont ici : MIA
lancée en local, sur des données fictives. **Jamais sur une instance réelle** : l'image est publiée telle quelle, et le
contrôle anti-fuite du wiki ne lit pas les images
([standard 13 de my-docs](https://github.com/MyAlfred-ai/my-docs/blob/main/standards/13-doc-utilisateur.md#captures-décran)).

## Refaire une capture

Il faut Docker, PHP 8.3 ou plus avec `pdo_mysql` (le serveur intégré suffit ; `vendor/` est versionné),
Google Chrome, et un clone de my-docs à côté, outil installé :
`npx --yes pnpm@12.3.4 install --dir tools/captures`.

1. `docs/tech/captures/lancer.sh` — MySQL jetable sur le port 3307, migrations, données fictives,
   serveur PHP sur le 8190. Il tourne à côté de la pile de dev sans la toucher. Ctrl+C arrête tout.
2. Depuis my-docs : `node tools/captures/capture.mjs mia --chemin ../MIA/myLLM`.
3. Relire l'image, puis la commiter avec le changement d'écran qui l'a rendue nécessaire.

## Les fichiers

| Fichier | Rôle |
|---|---|
| `lancer.sh` | MIA en local, sur une base en mémoire. Aucune clé d'API : chaque fournisseur d'IA refuse avant de sortir sur le réseau |
| `donnees-fictives.php` | Camille Martin, sept conversations, deux assistants, deux bases de connaissances ; et sa session PHP, qui la connecte sans mot de passe |
| `ecran-principal.capture.mjs` | La conversation à ouvrir, ce qu'on masque, et les dix numéros, dans l'ordre du tableau de la page |

## Quand l'écran change

- **Un numéro qui ne trouve plus sa cible arrête l'outil** : corriger sa `cible` — un identifiant ou un
  libellé stable ; le JavaScript réécrit certains `title` (le bouton des bases de connaissances).
- **Une zone ajoutée ou retirée** : le repère et la ligne du tableau de la page changent dans le même
  commit.
- **Une donnée à montrer** : elle s'ajoute à `donnees-fictives.php`, inventée. Aucune donnée copiée
  d'une vraie base, même « anonymisée ».
- **Le temps de réponse est absent de la capture** tant que son étiquette affiche « &nbsp; » en toutes
  lettres : `response_ms` est laissé vide dans `donnees-fictives.php`, à remettre une fois corrigé.
