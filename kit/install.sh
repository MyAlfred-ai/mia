#!/usr/bin/env bash
set -euo pipefail
KIT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/kit.sh
. "$KIT_DIR/lib/kit.sh"

erreur() { kit_erreur "$*"; exit 1; }
commande_requise() { command -v "$1" >/dev/null 2>&1 || erreur "commande requise absente : $1"; }
valider_email() {
  [[ "$1" =~ ^[^@[:space:]]+@[^@[:space:]]+([.][^@[:space:]]+)+$ ]]
}
demander() {
  local invite=$1 defaut=$2 valeur
  read -r -p "$invite [$defaut] : " valeur
  printf '%s' "${valeur:-$defaut}"
}
generer_secret() { openssl rand -hex 32; }

AFFICHER_AIDE=0
CHECK_SEUL=0
FORCER_NON_INTERACTIF=0
for argument in "$@"; do
  case "$argument" in
    --help|-h) AFFICHER_AIDE=1 ;;
    --check) CHECK_SEUL=1 ;;
    --non-interactive) FORCER_NON_INTERACTIF=1 ;;
    *) erreur "option inconnue : $argument (utilisez --help)" ;;
  esac
done
if [ "$AFFICHER_AIDE" -eq 1 ]; then
  cat <<'EOF'
Usage : bash install.sh [--help] [--check] [--non-interactive]

Installation par images publiées sur GHCR ; aucune compilation ni opération Git.
APP_URL et MIA_ADMIN_EMAIL sont obligatoires sans terminal.
Variables optionnelles : MIA_VERSION, COMPOSE_PROJECT_NAME, MIA_WORKERS,
MIA_INSTALL_MODE=standalone|proxy, APP_PORT, APP_BIND, PROXY_NETWORK,
MIA_ADMIN_NAME et MIA_ADMIN_PASSWORD.
EOF
  exit 0
fi

commande_requise docker
commande_requise openssl
docker compose version >/dev/null 2>&1 || erreur "Docker Compose v2 est requis"
docker info >/dev/null 2>&1 || erreur "le démon Docker ne répond pas"
if [ "$CHECK_SEUL" -eq 1 ]; then
  kit_dire "Docker, Compose v2, démon Docker et openssl sont disponibles."
  exit 0
fi

if [ -e "$KIT_ENV" ]; then
  erreur ".env existe déjà ; sauvegardez-le puis relancez l'installation explicitement"
fi
[ ! -e "$KIT_ENV.tmp" ] || erreur ".env.tmp existe ; examinez-le puis retirez-le avant de recommencer"

INTERACTIF=0
if [ -t 0 ] && [ "$FORCER_NON_INTERACTIF" -eq 0 ]; then INTERACTIF=1; fi
APP_URL=${APP_URL:-}
COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-mia}
VERSION_DEFAUT=1.6.0
if [ -r "$KIT_DIR/VERSION" ]; then
  VERSION_DEFAUT=$(sed -n '1p' "$KIT_DIR/VERSION")
fi
MIA_VERSION=${MIA_VERSION:-$VERSION_DEFAUT}
MIA_WORKERS=${MIA_WORKERS:-4}
APP_PORT=${APP_PORT:-8080}
APP_BIND=${APP_BIND:-0.0.0.0}
MIA_DOWNLOADS_DIR=${MIA_DOWNLOADS_DIR:-./downloads}
MIA_INSTALL_MODE=${MIA_INSTALL_MODE:-standalone}
PROXY_NETWORK=${PROXY_NETWORK:-}
MIA_ADMIN_EMAIL=${MIA_ADMIN_EMAIL:-}
MIA_ADMIN_NAME=${MIA_ADMIN_NAME:-Administrateur}
MIA_ADMIN_PASSWORD=${MIA_ADMIN_PASSWORD:-}

if [ "$INTERACTIF" -eq 1 ]; then
  APP_URL=$(demander "URL publique complète (https://…)" "${APP_URL:-https://mia.example.test}")
  COMPOSE_PROJECT_NAME=$(demander "Nom du projet Compose (stable, pour conserver les volumes)" "$COMPOSE_PROJECT_NAME")
  MIA_VERSION=$(demander "Version MIA (X.Y.Z ou edge)" "$MIA_VERSION")
  MIA_INSTALL_MODE=$(demander "Mode (standalone ou proxy)" "$MIA_INSTALL_MODE")
  if [ "$MIA_INSTALL_MODE" = proxy ]; then
    PROXY_NETWORK=$(demander "Réseau Docker du reverse proxy" "${PROXY_NETWORK:-proxy}")
  else
    APP_PORT=$(demander "Port HTTP local" "$APP_PORT")
  fi
  MIA_ADMIN_EMAIL=$(demander "E-mail du premier administrateur" "$MIA_ADMIN_EMAIL")
  MIA_ADMIN_NAME=$(demander "Nom du premier administrateur" "$MIA_ADMIN_NAME")
  if [ -z "$MIA_ADMIN_PASSWORD" ]; then
    read -r -s -p "Mot de passe admin (vide = génération aléatoire) : " MIA_ADMIN_PASSWORD
    printf '\n'
  fi
fi

[ -n "$APP_URL" ] || erreur "APP_URL est obligatoire"
[[ "$APP_URL" =~ ^https?://[^/[:space:]]+ ]] || erreur "APP_URL doit être une URL http(s) complète"
[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || erreur "COMPOSE_PROJECT_NAME invalide"
kit_version_valide "$MIA_VERSION" || erreur "version invalide : $MIA_VERSION"
[[ "$MIA_WORKERS" =~ ^[1-9][0-9]*$ ]] || erreur "MIA_WORKERS doit être un entier positif"
[[ "$APP_PORT" =~ ^[1-9][0-9]{0,4}$ ]] && [ "$APP_PORT" -le 65535 ] || erreur "APP_PORT invalide"
case "$MIA_INSTALL_MODE" in
  standalone) COMPOSE_FILE=docker-compose.yml ;;
  proxy)
    [ -n "$PROXY_NETWORK" ] || erreur "PROXY_NETWORK est obligatoire en mode proxy"
    if ! docker network inspect "$PROXY_NETWORK" >/dev/null 2>&1; then
      erreur "le réseau Docker '$PROXY_NETWORK' n'existe pas"
    fi
    COMPOSE_FILE=docker-compose.yml:docker-compose.proxy.yml
    ;;
  *) erreur "MIA_INSTALL_MODE doit être standalone ou proxy" ;;
esac
valider_email "$MIA_ADMIN_EMAIL" || erreur "une adresse e-mail administrateur valide est obligatoire"
if [ -n "$MIA_ADMIN_PASSWORD" ] && [ "${#MIA_ADMIN_PASSWORD}" -lt 8 ]; then
  erreur "MIA_ADMIN_PASSWORD doit comporter au moins 8 caractères"
fi
ADMIN_MDP_GENERE=0
if [ -z "$MIA_ADMIN_PASSWORD" ]; then
  MIA_ADMIN_PASSWORD=$(openssl rand -hex 16)
  ADMIN_MDP_GENERE=1
fi

umask 077
trap 'rm -f "$KIT_ENV.tmp"' EXIT
cat > "$KIT_ENV.tmp" <<EOF
COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME
MIA_VERSION=$MIA_VERSION
MIA_WORKERS=$MIA_WORKERS
APP_URL=$APP_URL
APP_ENV=production
APP_SECRET=$(generer_secret)
ENCRYPTION_KEY=$(generer_secret)
TRUSTED_PROXY=0
DB_HOST=db
DB_PORT=3306
DB_NAME=mia
DB_USER=mia
DB_PASS=$(generer_secret)
DB_ROOT_PASS=$(generer_secret)
COMPOSE_FILE=$COMPOSE_FILE
APP_BIND=$APP_BIND
APP_PORT=$APP_PORT
MIA_DOWNLOADS_DIR=$MIA_DOWNLOADS_DIR
PROXY_NETWORK=$PROXY_NETWORK
QDRANT_URL=http://qdrant:6333
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
GOOGLE_CLIENT_ID_IOS=
LOCAL_PASSWORD_HASH=
EOF
chmod 600 "$KIT_ENV.tmp"
mv "$KIT_ENV.tmp" "$KIT_ENV"
case "$MIA_DOWNLOADS_DIR" in
  /*) mkdir -p "$MIA_DOWNLOADS_DIR" ;;
  *) mkdir -p "$KIT_DIR/$MIA_DOWNLOADS_DIR" ;;
esac

kit_verifier_variables || exit 1
kit_verifier_disque || exit 1
kit_dire "vérification de la signature de MIA $MIA_VERSION"
kit_verifier_signatures "$MIA_VERSION" || exit 1
kit_dire "téléchargement des images"
kit_compose pull || erreur "le téléchargement d'une image a échoué"
kit_dire "démarrage de MIA"
if ! kit_demarrer; then
  kit_compose logs --tail 100 || true
  erreur "MIA n'est pas sain ; le .env est conservé pour permettre le diagnostic"
fi

if ! printf '%s' "$MIA_ADMIN_PASSWORD" | kit_compose exec -T app \
    php scripts/admin-create.php "--email=$MIA_ADMIN_EMAIL" "--name=$MIA_ADMIN_NAME"; then
  erreur "création du compte admin échouée ; les conteneurs restent démarrés"
fi

kit_dire "installation terminée : $APP_URL"
kit_dire "compte administrateur : $MIA_ADMIN_EMAIL"
if [ "$ADMIN_MDP_GENERE" -eq 1 ]; then
  printf 'Mot de passe admin (à conserver maintenant) : %s\n' "$MIA_ADMIN_PASSWORD"
fi
