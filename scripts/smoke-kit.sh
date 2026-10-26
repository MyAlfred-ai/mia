#!/usr/bin/env bash
# Exercices de mise à jour et restauration du kit MIA avec des images locales.
set -euo pipefail

racine=$(cd "$(dirname "$0")/.." && pwd)
travail=$(mktemp -d)
kit="$travail/kit"
v1=0.0.1-smoke
v2=0.0.2-smoke
v3=0.0.3-smoke
projet=mia-kit-smoke
port=${SMOKE_PORT:-18099}
docker_reel=$(command -v docker)

etape() { printf '\n== %s\n' "$*"; }
echec() { echo "ÉCHEC : $*" >&2; exit 1; }

nettoyer() {
  local code=$?
  if [ -f "$kit/.env" ]; then
    if [ "$code" -ne 0 ]; then
      kit_compose ps >&2 || true
      kit_compose logs --tail 100 >&2 || true
    fi
    kit_compose down -v --remove-orphans >/dev/null 2>&1 || true
  fi
  rm -rf "$travail"
}
trap nettoyer EXIT

mkdir -p "$travail/bin" "$kit"
cat > "$travail/bin/docker" <<'EOF'
#!/usr/bin/env bash
if [ "$1" = run ] && [[ "$*" == *gcr.io/projectsigstore/cosign:* ]]; then
  exit 0
fi
if [ "$1" = compose ] && [[ " $* " == *" pull "* ]]; then
  exit 0
fi
if [ "$1" = build ]; then
  local_context="${@: -1}"
  if [[ "$local_context" == /* ]] && [ -d "$local_context" ]; then
    arguments=("${@:1:$#-1}" "$(cygpath -m "$local_context")")
    set -- "${arguments[@]}"
  fi
fi
arguments=()
while [ "$#" -gt 0 ]; do
  if [[ "$1" == --project-directory || "$1" == --env-file || "$1" == -f ]] \
      && [[ "${2:-}" == /* ]]; then
    arguments+=("$1" "$(cygpath -m "$2")")
    shift 2
    continue
  fi
  if [ "$1" = -v ] && [[ "${2:-}" == /*:* ]]; then
    source=${2%%:*}
    rest=${2#*:}
    arguments+=(-v "$(cygpath -m "$source"):$rest")
    shift 2
  else
    arguments+=("$1")
    shift
  fi
done
MSYS_NO_PATHCONV=1 exec "$SMOKE_DOCKER_BIN" "${arguments[@]}"
EOF
chmod +x "$travail/bin/docker"
export SMOKE_DOCKER_BIN="$docker_reel"
export PATH="$travail/bin:$PATH"
echo "Vérification cosign simulée ; images construites localement."

cp -R "$racine/kit/." "$kit/"
kit_compose() {
  docker compose --project-directory "$kit" --env-file "$kit/.env" \
    -f "$kit/docker-compose.yml" -f "$kit/docker-compose.smoke.yml" "$@"
}

etape "Construction des images locales"
docker build -q -t "ghcr.io/myalfred-ai/mia:$v1" "$racine" >/dev/null
docker tag "ghcr.io/myalfred-ai/mia:$v1" "ghcr.io/myalfred-ai/mia:$v2"
docker build -q -t "ghcr.io/myalfred-ai/mia:$v3" - <<EOF >/dev/null
FROM ghcr.io/myalfred-ai/mia:$v2
ENV SMOKE_BROKEN=1
EOF

cat > "$kit/docker-compose.smoke.yml" <<'EOF'
services:
  app:
    healthcheck:
      test: ["CMD-SHELL", "test \"$$SMOKE_BROKEN\" != 1 && curl -fsS http://127.0.0.1/healthz >/dev/null"]
      interval: 2s
      timeout: 2s
      start_period: 1s
      retries: 1
EOF

cat > "$kit/.env" <<EOF
COMPOSE_PROJECT_NAME=$projet
MIA_VERSION=$v1
MIA_WORKERS=1
APP_URL=http://127.0.0.1:$port
APP_ENV=production
APP_SECRET=$(openssl rand -hex 32)
ENCRYPTION_KEY=$(openssl rand -hex 32)
TRUSTED_PROXY=0
DB_HOST=db
DB_PORT=3306
DB_NAME=mia
DB_USER=mia
DB_PASS=$(openssl rand -hex 24)
DB_ROOT_PASS=$(openssl rand -hex 24)
COMPOSE_FILE=docker-compose.yml:docker-compose.smoke.yml
APP_BIND=127.0.0.1
APP_PORT=$port
MIA_DOWNLOADS_DIR=./downloads
QDRANT_URL=http://qdrant:6333
EOF
chmod 600 "$kit/.env"
mkdir -p "$kit/downloads"
export KIT_DIR="$kit"
# shellcheck source=../kit/lib/kit.sh
# shellcheck disable=SC1091
. "$kit/lib/kit.sh"

etape "Démarrage en $v1"
SANTE_DELAI=240 kit_demarrer || echec "MIA $v1 n'est pas devenue saine"
curl -fsS "http://127.0.0.1:$port/healthz" | grep -q '"status":"ok' \
  || echec "/healthz ne confirme pas l'état ok"

mysql_exec() {
  # La commande est développée par le shell dans le conteneur MySQL.
  # shellcheck disable=SC2016
  kit_compose exec -T db sh -c \
    'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -N -e "$1"' \
    sh "$1"
}

mysql_verifier() {
  local valeur
  valeur=$(mysql_exec "SELECT value FROM smoke_validation WHERE id = 'smoke'")
  [ "$valeur" = "$1" ] || echec "donnée MySQL inattendue : attendu « $1 », obtenu « $valeur »"
}

qdrant_ecrire_marqueur() {
  local cid
  cid=$(kit_compose ps -q qdrant)
  docker run --rm --volumes-from "$cid" alpine:3.22 \
    sh -c 'printf "%s" "$1" > /qdrant/storage/smoke-validation.marker' sh "$1"
}

qdrant_verifier_marqueur() {
  local cid valeur
  cid=$(kit_compose ps -q qdrant)
  valeur=$(docker run --rm --volumes-from "$cid" alpine:3.22 \
    cat /qdrant/storage/smoke-validation.marker)
  [ "$valeur" = "$1" ] || echec "marqueur Qdrant inattendu : attendu « $1 », obtenu « $valeur »"
}

etape "Création d'une donnée témoin"
mysql_exec 'CREATE TABLE smoke_validation (id VARCHAR(30) PRIMARY KEY, value VARCHAR(100) NOT NULL); INSERT INTO smoke_validation VALUES ("smoke", "initial")'
qdrant_ecrire_marqueur initial

etape "Mise à jour réussie"
SANTE_DELAI=240 bash "$kit/update.sh" "$v2" \
  || echec "update.sh $v2 a échoué ($?)"
[ "$(kit_lire MIA_VERSION)" = "$v2" ] || echec "MIA_VERSION n'est pas passée à $v2"
mysql_verifier initial
qdrant_verifier_marqueur initial
echo "Mise à jour code 0, donnée MySQL conservée"

etape "Rollback explicite"
SANTE_DELAI=240 bash "$kit/rollback.sh" || echec "rollback.sh a échoué ($?)"
[ "$(kit_lire MIA_VERSION)" = "$v1" ] || echec "MIA_VERSION n'est pas revenue à $v1"
mysql_verifier initial
qdrant_verifier_marqueur initial
echo "Rollback explicite réussi, donnée MySQL conservée"

etape "Échec volontaire de $v3 et retour automatique"
SANTE_DELAI=240 bash "$kit/update.sh" "$v2" \
  || echec "second update.sh $v2 a échoué ($?)"
mysql_exec "UPDATE smoke_validation SET value = 'avant-echec' WHERE id = 'smoke'"
qdrant_ecrire_marqueur avant-echec
set +e
SANTE_DELAI=45 SANTE_PAS=1 bash "$kit/update.sh" "$v3"
code=$?
set -e
[ "$code" -eq 1 ] || echec "update cassé devait sortir avec 1, obtenu $code"
[ "$(kit_lire MIA_VERSION)" = "$v2" ] || echec "la version $v2 n'a pas été restaurée"
SANTE_DELAI=240 kit_attendre_sante || echec "$v2 n'est pas saine après retour automatique"
mysql_verifier avant-echec
qdrant_verifier_marqueur avant-echec
echo "Code 1, version $v2 saine, données MySQL et Qdrant restaurées"

etape "Réussi"
