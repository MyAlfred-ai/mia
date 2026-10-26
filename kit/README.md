# Kit d'installation MIA

Le kit déploie des images publiées sur GHCR : il ne clone pas le dépôt source et
ne compile pas l'application sur le serveur. Les scripts s'exécutent depuis ce
répertoire et utilisent le fichier `.env` local.

## Première installation

1. Extraire le kit dans un dossier dédié et conserver ce dossier au même chemin.
2. Exécuter `bash install.sh` depuis un terminal Bash. L'installateur demande
   l'URL publique, le nom stable du projet Compose, la version, le mode réseau
   et l'e-mail du premier administrateur.
3. Il génère les secrets, vérifie la signature Cosign de l'image, télécharge
   les images, applique d'abord les migrations avec l'application, puis démarre
   les workers et attend que tous les services soient sains.
4. Le fichier `.env` contient des secrets : il est créé avec les permissions
   `0600`. Ne pas le publier ni le remplacer lors d'une mise à jour.

Le mode `standalone` publie l'application sur `APP_BIND:APP_PORT` (8080 par
défaut). Le mode `proxy` raccorde l'application à un réseau Docker externe déjà
créé ; `PROXY_NETWORK` doit désigner ce réseau. Après l'installation, adapter
les paramètres optionnels dans `.env` avant de démarrer une mise à jour.
Le PHP ini pour les variables de l'application est fourni dans le kit.
`MIA_DOWNLOADS_DIR` (par défaut `./downloads`) désigne le dossier des APK
publiés ; les fichiers présents y sont servis par MIA.

Le kit inclut MySQL et Qdrant dans le Compose : il ne prend pas en charge une
base MySQL externe. Les installations existantes avec une base externe ne
doivent pas être migrées vers ce kit sans plan de migration dédié.

## Exploitation

```bash
bash backup.sh
bash update.sh 1.6.0
bash rollback.sh
```

Les versions acceptées sont `X.Y.Z`, les versions préliminaires explicites
(`X.Y.Z-rc1`) et `edge`. L'update vérifie la configuration, l'espace disque,
crée un dump MySQL, archive les données persistantes et le stockage Qdrant,
vérifie la signature de l'image, puis déploie. Si la nouvelle application ne
devient pas saine, le kit restaure la sauvegarde et l'image précédente.

Codes de sortie : `0` succès, `1` mise à jour refusée/échouée avec retour arrière
réussi, `2` retour arrière incomplet nécessitant une intervention. Aucun script
du kit ne lance Git.

Les sauvegardes sont conservées sous `sauvegardes/`. Les restaurations de base
et de fichiers doivent être testées sur une instance de recette avant une mise
à jour de production. Ne pas supprimer les volumes Docker ni le `.env` lors
d'un changement de version.

Pour tester localement la sauvegarde, l'update et le rollback depuis le dépôt
source, exécuter `scripts/smoke-kit.sh`. Ce scénario utilise des images locales
et simule la vérification Cosign ; il ne valide pas les signatures d'une release
publiée.
