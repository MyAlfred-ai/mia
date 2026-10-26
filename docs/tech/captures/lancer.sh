#!/usr/bin/env bash
# MIA en local, sur une base jetable remplie de données fictives : l'état dont partent les captures
# de la documentation utilisateur (voir README.md, à côté). Ctrl+C arrête tout ; la base, en
# mémoire, disparaît avec son conteneur.
set -euo pipefail
cd "$(dirname "$0")/../../.."

PORT="${CAPTURE_PORT:-8190}"
MYSQL_PORT="${CAPTURE_MYSQL_PORT:-3307}"
MYSQL_NOM="mia-captures-mysql"
SESSIONS="$(mktemp -d)"

# Un lanceur tué sans Ctrl+C laisse sa base derrière lui : on l'arrête avant d'en créer une
# autre (--rm : arrêtée, elle s'efface).
docker stop "$MYSQL_NOM" >/dev/null 2>&1 || true
# Port à part de la pile de dev : les deux tournent côte à côte sans se mélanger.
docker run -d --rm --name "$MYSQL_NOM" -p "127.0.0.1:${MYSQL_PORT}:3306" \
  -e MYSQL_ROOT_PASSWORD=captures -e MYSQL_DATABASE=myllm -e MYSQL_USER=myllm -e MYSQL_PASSWORD=captures \
  --tmpfs /var/lib/mysql mysql:8.4 >/dev/null
WEB=""
arreter() {
  trap - EXIT INT TERM
  [ -n "$WEB" ] && kill "$WEB" 2>/dev/null
  docker stop "$MYSQL_NOM" >/dev/null 2>&1 || true
  rm -rf "$SESSIONS"
}
trap arreter EXIT INT TERM

export DB_HOST=127.0.0.1 DB_PORT="$MYSQL_PORT" DB_NAME=myllm DB_USER=myllm DB_PASS=captures
export APP_URL="http://127.0.0.1:${PORT}" APP_ENV=development
export APP_SECRET="$(openssl rand -hex 32)" ENCRYPTION_KEY="$(openssl rand -hex 32)"
# Aucune clé d'API n'est enregistrée : chaque fournisseur d'IA refuse avant de sortir sur le
# réseau. Qdrant pointe sur un port local fermé : l'index vectoriel échoue sur place.
export QDRANT_URL="http://127.0.0.1:9"
export CAPTURE_SESSIONS="$SESSIONS"

# MySQL répond au ping avant d'avoir créé son utilisateur : on réessaie la migration.
for _ in $(seq 1 60); do
  php docker/migrate.php >/dev/null 2>&1 && break
  sleep 1
done
php -d session.save_path="$SESSIONS" docs/tech/captures/donnees-fictives.php

# Serveur intégré de PHP, sessions dans le dossier que le jeu de données vient de remplir.
# -t public : router.php laisse le serveur servir les fichiers statiques, qu'il cherche là.
php -d session.save_path="$SESSIONS" -S "127.0.0.1:${PORT}" -t public router.php &
WEB=$!
wait "$WEB"
