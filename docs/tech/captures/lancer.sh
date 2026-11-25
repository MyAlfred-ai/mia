#!/usr/bin/env bash
# MIA en local, sur une base jetable remplie de données fictives : l'état dont partent les captures
# de la documentation utilisateur (voir README.md, à côté). Ctrl+C arrête tout ; la base et l'index
# vectoriel, en mémoire, disparaissent avec leurs conteneurs.
set -euo pipefail
cd "$(dirname "$0")/../../.."

PORT="${CAPTURE_PORT:-8190}"
MYSQL_PORT="${CAPTURE_MYSQL_PORT:-3307}"
QDRANT_PORT="${CAPTURE_QDRANT_PORT:-6335}"
# Noms et ports réglables : deux bancs peuvent tourner côte à côte (deux worktrees).
MYSQL_NOM="${CAPTURE_MYSQL_NOM:-mia-captures-mysql}"
QDRANT_NOM="${CAPTURE_QDRANT_NOM:-mia-captures-qdrant}"
SESSIONS="$(mktemp -d)"

# Un lanceur tué sans Ctrl+C laisse ses conteneurs derrière lui : on les retire avant d'en créer
# d'autres. `rm -f`, pas `stop` : un conteneur --rm arrêté met un instant à s'effacer, et son nom
# reste pris pendant ce temps.
docker rm -f "$MYSQL_NOM" "$QDRANT_NOM" >/dev/null 2>&1 || true
# Ports à part de la pile de dev : les deux tournent côte à côte sans se mélanger.
docker run -d --rm --name "$MYSQL_NOM" -p "127.0.0.1:${MYSQL_PORT}:3306" \
  -e MYSQL_ROOT_PASSWORD=captures -e MYSQL_DATABASE=myllm -e MYSQL_USER=myllm -e MYSQL_PASSWORD=captures \
  --tmpfs /var/lib/mysql mysql:8.4 >/dev/null
# Le Qdrant de docker-compose.yml, vide : les pages des bases de connaissances le trouvent en
# service, comme sur une instance réelle, au lieu d'afficher « moteur vectoriel indisponible ».
docker run -d --rm --name "$QDRANT_NOM" -p "127.0.0.1:${QDRANT_PORT}:6333" \
  --tmpfs /qdrant/storage qdrant/qdrant:v1.15.1 >/dev/null
WEB=""
arreter() {
  trap - EXIT INT TERM
  [ -n "$WEB" ] && kill "$WEB" 2>/dev/null
  docker stop "$MYSQL_NOM" "$QDRANT_NOM" >/dev/null 2>&1 || true
  rm -rf "$SESSIONS"
}
trap arreter EXIT INT TERM

export DB_HOST=127.0.0.1 DB_PORT="$MYSQL_PORT" DB_NAME=myllm DB_USER=myllm DB_PASS=captures
export APP_URL="http://127.0.0.1:${PORT}" APP_ENV=development
export APP_SECRET="$(openssl rand -hex 32)" ENCRYPTION_KEY="$(openssl rand -hex 32)"
export QDRANT_URL="http://127.0.0.1:${QDRANT_PORT}"
# Un client Google factice : les connecteurs Google sont alors proposés, et les comptes reliés par
# le jeu de données s'affichent. Il ne sert qu'à ça — aucune connexion Google n'aboutirait.
export GOOGLE_CLIENT_ID="captures-documentation.apps.googleusercontent.com" GOOGLE_CLIENT_SECRET="factice"
export CAPTURE_SESSIONS="$SESSIONS"
# Les clés d'API du jeu de données sont factices, et rien ne doit sortir de la machine, même sur un
# clic malheureux (Envoyer, Tester) : hors-ligne.php retire à PHP ses flux https://, et ce proxy
# fermé arrête curl. Seul 127.0.0.1 reste joignable — la base, Qdrant.
export http_proxy="http://127.0.0.1:9" https_proxy="http://127.0.0.1:9" HTTPS_PROXY="http://127.0.0.1:9"
export no_proxy="127.0.0.1,localhost"
HORS_LIGNE="docs/tech/captures/hors-ligne.php"

# MySQL répond au ping avant d'avoir créé son utilisateur : on réessaie la migration.
for _ in $(seq 1 60); do
  php docker/migrate.php >/dev/null 2>&1 && break
  sleep 1
done
php -d session.save_path="$SESSIONS" docs/tech/captures/donnees-fictives.php

# Serveur intégré de PHP, sessions dans le dossier que le jeu de données vient de remplir.
# -t public : router.php laisse le serveur servir les fichiers statiques, qu'il cherche là.
php -d session.save_path="$SESSIONS" -d auto_prepend_file="$PWD/$HORS_LIGNE" \
  -S "127.0.0.1:${PORT}" -t public router.php &
WEB=$!
wait "$WEB"
