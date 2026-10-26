#!/bin/sh
# =============================================================================
# MIA — installation.
#
#   ./install.sh                      installation guidée (questions)
#   ./install.sh --non-interactive    installation silencieuse (voir plus bas)
#   ./install.sh --force              réécrit un .env existant (sauvegardé, secrets repris)
#   ./install.sh --check              contrôles préalables seulement, n'écrit rien
#
# Variables reconnues en mode non interactif (toutes optionnelles sauf MIA_ADMIN_EMAIL) :
#   MIA_MODE          standalone | traefik | proxy | dev  (défaut : standalone)
#   MIA_URL           adresse publique complète        (défaut : http://localhost:PORT)
#   MIA_DOMAIN        nom d'hôte, mode traefik         (déduit de MIA_URL sinon)
#   MIA_PORT          port publié sur l'hôte           (défaut : 8080)
#   MIA_DB_HOST       base externe : hôte              (défaut : base embarquée)
#   MIA_DB_PORT       base externe : port              (défaut : 3306)
#   MIA_DB_NAME/USER/PASS   identifiants de la base
#   MIA_ADMIN_EMAIL   e-mail du premier administrateur (obligatoire, sans défaut)
#   MIA_ADMIN_NAME    son nom affiché                  (défaut : Administrateur)
#   MIA_ADMIN_PASSWORD  son mot de passe, 8 caractères au moins (tiré au hasard sinon)
#   MIA_GOOGLE_CLIENT_ID / MIA_GOOGLE_CLIENT_SECRET
#
# Le script ne modifie RIEN tant que tous les contrôles préalables ne sont pas
# passés : en cas d'échec, il s'arrête en expliquant quoi corriger.
# =============================================================================

set -e

cd "$(dirname "$0")"
RACINE=$(pwd)

FORCE=0
INTERACTIF=1
CHECK_SEUL=0

for argument in "$@"; do
    case "$argument" in
        --force)            FORCE=1 ;;
        --non-interactive)  INTERACTIF=0 ;;
        --check)            CHECK_SEUL=1 ;;
        -h|--help)          sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Option inconnue : $argument (--help pour l'aide)" >&2; exit 1 ;;
    esac
done

[ -t 0 ] || INTERACTIF=0

# ---------------------------------------------------------------- Affichage

if [ -t 1 ]; then
    G='\033[32m'; J='\033[33m'; R='\033[31m'; B='\033[1m'; Z='\033[0m'
else
    G=''; J=''; R=''; B=''; Z=''
fi

titre()   { printf '\n%b%s%b\n' "$B" "$1" "$Z"; }
ok()      { printf '  %b✓%b %s\n' "$G" "$Z" "$1"; }
alerte()  { printf '  %b!%b %s\n' "$J" "$Z" "$1"; }
echec()   { printf '  %b✗%b %s\n' "$R" "$Z" "$1"; }
remede()  { printf '      → %s\n' "$1"; }

PROBLEMES=0
probleme() { echec "$1"; [ -n "$2" ] && remede "$2"; PROBLEMES=$((PROBLEMES + 1)); }

# ---------------------------------------------------------------- 1. Contrôles préalables

titre "1. Contrôles préalables"

if command -v docker >/dev/null 2>&1; then
    ok "docker présent"
else
    probleme "docker est introuvable." "Installez Docker Engine : https://docs.docker.com/engine/install/"
fi

if docker compose version >/dev/null 2>&1; then
    ok "docker compose v2 présent ($(docker compose version --short 2>/dev/null || echo '?'))"
else
    probleme "« docker compose » (v2) est introuvable." \
             "Le plugin Compose v2 est requis. « docker-compose » v1 n'est pas supporté."
fi

if docker info >/dev/null 2>&1; then
    ok "le démon Docker répond"
else
    probleme "le démon Docker ne répond pas." "Démarrez Docker, ou ajoutez votre utilisateur au groupe « docker »."
fi

if command -v openssl >/dev/null 2>&1; then
    ok "openssl présent (génération des secrets)"
elif [ -r /dev/urandom ]; then
    alerte "openssl absent — les secrets seront tirés depuis /dev/urandom"
else
    probleme "aucune source d'aléa (ni openssl, ni /dev/urandom)." "Installez openssl."
fi

if command -v curl >/dev/null 2>&1; then
    ok "curl présent"
else
    alerte "curl absent sur l'hôte — la vérification finale passera par le conteneur"
fi

ESPACE_KO=$(df -Pk . 2>/dev/null | awk 'NR==2 {print $4}')
if [ -n "$ESPACE_KO" ] && [ "$ESPACE_KO" -lt 5242880 ]; then
    alerte "moins de 5 Go libres ($((ESPACE_KO / 1024)) Mo) — les images Docker en occupent environ 2 Go"
else
    ok "espace disque suffisant"
fi

if [ -w "$RACINE" ]; then
    ok "le répertoire d'installation est inscriptible"
else
    probleme "impossible d'écrire dans $RACINE." "Corrigez les droits, ou clonez ailleurs."
fi

case "$(uname -m)" in
    arm64|aarch64) alerte "architecture ARM détectée — voir docs/INSTALLATION.md si la construction échoue" ;;
    *) ok "architecture $(uname -m)" ;;
esac

if [ -f .env ] && [ "$FORCE" -ne 1 ] && [ "$CHECK_SEUL" -ne 1 ]; then
    echo
    echec "un fichier .env existe déjà : rien n'a été modifié."
    remede "Pour le remplacer : ./install.sh --force (sauvegardé dans .env.bak.<date>, ses secrets repris)"
    remede "Pour seulement (re)démarrer : docker compose up -d"
    remede "Pour diagnostiquer : docker compose exec app php scripts/doctor.php"
    exit 1
fi

if [ "$PROBLEMES" -gt 0 ]; then
    echo
    printf '%b%s%b\n' "$R" "  $PROBLEMES point(s) bloquant(s). Rien n'a été modifié." "$Z"
    exit 1
fi

if [ "$CHECK_SEUL" -eq 1 ]; then
    echo
    ok "Contrôles préalables tous passés."
    exit 0
fi

# ---------------------------------------------------------------- 2. Questions

# Fin de saisie (Ctrl-D) : on abandonne avec un message, au lieu de sortir sans
# un mot — et, sur une saisie masquée, sans rendre l'écho au terminal. Toutes
# les questions précèdent l'écriture du .env : rien n'a encore été modifié.
abandon() {
    echo
    echec "saisie interrompue : rien n'a été modifié."
    exit 1
}

demander() { # demander <invite> <defaut> <variable_resultat>
    reponse=''
    if [ "$INTERACTIF" -eq 1 ]; then
        if [ -n "$2" ]; then printf '  %s [%s] : ' "$1" "$2"; else printf '  %s : ' "$1"; fi
        read -r reponse || abandon
    fi
    [ -z "$reponse" ] && reponse="$2"
    eval "$3=\$reponse"
}

demander_secret() { # demander_secret <invite> <variable_resultat>
    reponse=''
    if [ "$INTERACTIF" -eq 1 ]; then
        printf '  %s : ' "$1"
        stty -echo 2>/dev/null || true
        lu=1
        read -r reponse || lu=0
        stty echo 2>/dev/null || true
        echo
        [ "$lu" -eq 1 ] || abandon
    fi
    eval "$2=\$reponse"
}

# Adresse de l'administrateur : même règle que FILTER_VALIDATE_EMAIL, qui en
# décide dans scripts/admin-create.php et sur l'écran /setup — PHP n'est pas
# encore là au moment de la question. Domaine d'au moins deux étiquettes
# (admin@localhost est refusée), la dernière commençant par une lettre ; mêmes
# longueurs maximales. Les deux formes rares que PHP accepte en plus (partie
# locale entre guillemets, adresse IP entre crochets) sont refusées ici : on
# redemande, rien n'est perdu. InstallationContractTest confronte cette
# fonction à PHP.
ATOME_EMAIL="[A-Za-z0-9!#\$%&'*+/=?^_\`{|}~-]"
MOTIF_EMAIL="^$ATOME_EMAIL+(\.$ATOME_EMAIL+)*@([A-Za-z0-9]+(-+[A-Za-z0-9]+)*\.)+[A-Za-z][A-Za-z0-9]*(-+[A-Za-z0-9]+)*\$"

email_valide() { # email_valide <adresse>
    case "$1" in *"
"*) return 1 ;; esac    # une seule ligne : grep jugerait chacune à part
    printf '%s\n' "$1" | LC_ALL=C grep -Eq "$MOTIF_EMAIL" || return 1
    partie_locale=${1%@*}
    [ "${#1}" -le 254 ] && [ "${#partie_locale}" -le 64 ] || return 1
    ! printf '%s\n' "${1#*@}" | LC_ALL=C grep -Eq '[^.]{64,}'
}

titre "2. Configuration"

MODE="${MIA_MODE:-}"
if [ -z "$MODE" ] && [ "$INTERACTIF" -eq 1 ]; then
    echo "  Comment MIA sera-t-elle jointe ?"
    echo "    1) Autonome — MIA publie un port, aucun reverse-proxy"
    echo "    2) Derrière Traefik — déjà installé sur cette machine"
    echo "    3) Derrière nginx ou Caddy — déjà installé sur cette machine"
    echo "    4) Développement local"
    demander "Votre choix" "1" CHOIX
    case "$CHOIX" in
        2) MODE=traefik ;;
        3) MODE=proxy ;;
        4) MODE=dev ;;
        *) MODE=standalone ;;
    esac
fi
MODE="${MODE:-standalone}"

case "$MODE" in
    standalone|traefik|proxy|dev) ;;
    *) echo "MIA_MODE invalide : $MODE (standalone | traefik | proxy | dev)" >&2; exit 1 ;;
esac

# Le mode détermine les fichiers compose ET l'interface d'écoute.
BIND=0.0.0.0
case "$MODE" in
    traefik) COMPOSE_FILE_VALEUR="docker-compose.yml:docker/compose/db.yml:docker/compose/traefik.yml" ;;
    dev)     COMPOSE_FILE_VALEUR="docker-compose.yml:docker/compose/db.yml:docker/compose/ports.yml:docker/compose/dev.yml"
             BIND=127.0.0.1 ;;
    proxy)   COMPOSE_FILE_VALEUR="docker-compose.yml:docker/compose/db.yml:docker/compose/ports.yml"
             # Le proxy tourne sur la même machine : inutile d'exposer au monde.
             BIND=127.0.0.1 ;;
    *)       COMPOSE_FILE_VALEUR="docker-compose.yml:docker/compose/db.yml:docker/compose/ports.yml" ;;
esac

PORT="${MIA_PORT:-}"
if [ "$MODE" != "traefik" ]; then
    [ -z "$PORT" ] && demander "Port publié sur l'hôte" "8080" PORT
    PORT="${PORT:-8080}"
fi

URL="${MIA_URL:-}"
DOMAINE="${MIA_DOMAIN:-}"
if [ "$MODE" = "traefik" ]; then
    [ -z "$DOMAINE" ] && demander "Nom de domaine (sans https://)" "" DOMAINE
    if [ -z "$DOMAINE" ]; then
        echo "  Un domaine est obligatoire en mode Traefik." >&2
        exit 1
    fi
    URL="${URL:-https://$DOMAINE}"
else
    [ -z "$URL" ] && demander "Adresse publique de MIA" "http://localhost:$PORT" URL
    URL="${URL:-http://localhost:$PORT}"
    DOMAINE=$(printf '%s' "$URL" | sed -e 's#^[a-z]*://##' -e 's#[:/].*$##')
fi

# Ports déjà pris ? Contrôle tardif : il dépend des ports choisis. Mieux vaut
# refuser ici que laisser `docker compose up` échouer à mi-parcours en laissant
# des conteneurs créés derrière lui.
port_occupe() {
    if command -v lsof >/dev/null 2>&1; then
        lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1 && return 0
    elif command -v ss >/dev/null 2>&1; then
        ss -ltnH 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$1\$" && return 0
    fi
    return 1
}

# Réinstallation (--force) d'une MIA en service : le port est tenu par son
# propre conteneur, que `docker compose up -d` recréera — pas un conflit.
# Sans ce cas, --force exigeait d'arrêter MIA d'abord.
port_de_mia() { # port_de_mia <service> <port_conteneur> <port_hote>
    [ -f .env ] && docker compose port "$1" "$2" 2>/dev/null | grep -q ":$3\$"
}

if [ "$MODE" != "traefik" ] && port_occupe "$PORT" && ! port_de_mia app 80 "$PORT"; then
    echo
    echec "le port $PORT est déjà utilisé sur cette machine."
    remede "Choisissez-en un autre (MIA_PORT=8081 ./install.sh) ou libérez celui-ci."
    exit 1
fi

# Le mode développement publie en plus le tableau de bord Qdrant.
if [ "$MODE" = "dev" ] && port_occupe "${QDRANT_PORT:-6333}" \
        && ! port_de_mia qdrant 6333 "${QDRANT_PORT:-6333}"; then
    echo
    echec "le port ${QDRANT_PORT:-6333} (tableau de bord Qdrant) est déjà utilisé."
    remede "Une autre instance de MIA tourne peut-être ici. Choisissez-en un autre : QDRANT_PORT=6334 ./install.sh"
    exit 1
fi

# Réseau Traefik : il doit préexister, aucun script ne le crée.
if [ "$MODE" = "traefik" ]; then
    RESEAU="${TRAEFIK_NETWORK:-ubuntu_default}"
    if docker network inspect "$RESEAU" >/dev/null 2>&1; then
        ok "réseau Docker « $RESEAU » présent"
    else
        echo
        echec "le réseau Docker « $RESEAU » n'existe pas."
        remede "C'est le réseau auquel Traefik est attaché. Vérifiez : docker network ls"
        remede "Puis relancez avec le bon nom : TRAEFIK_NETWORK=<nom> ./install.sh"
        exit 1
    fi
fi

# Base embarquée ou externe.
DB_HOTE="${MIA_DB_HOST:-}"
if [ -z "$DB_HOTE" ] && [ "$INTERACTIF" -eq 1 ]; then
    demander "Hôte MySQL (vide = base embarquée dans Docker)" "" DB_HOTE
fi
DB_NOM="${MIA_DB_NAME:-myllm}"
DB_UTILISATEUR="${MIA_DB_USER:-myllm}"
if [ -n "$DB_HOTE" ]; then
    # Base externe : on retire le fichier de la base embarquée.
    COMPOSE_FILE_VALEUR=$(printf '%s' "$COMPOSE_FILE_VALEUR" | sed 's#:docker/compose/db\.yml##')
    DB_PORT_VALEUR="${MIA_DB_PORT:-3306}"
    DB_MDP="${MIA_DB_PASS:-}"
    [ -z "$DB_MDP" ] && demander_secret "Mot de passe MySQL de « $DB_UTILISATEUR »" DB_MDP
    if [ -z "$DB_MDP" ]; then
        echo "  Un mot de passe est obligatoire pour une base externe." >&2
        exit 1
    fi
    DB_MDP_ROOT=''
else
    DB_HOTE=db
    DB_PORT_VALEUR=3306
    DB_MDP=''
    DB_MDP_ROOT=''
fi

GOOGLE_ID="${MIA_GOOGLE_CLIENT_ID:-}"
GOOGLE_SECRET="${MIA_GOOGLE_CLIENT_SECRET:-}"
if [ "$INTERACTIF" -eq 1 ] && [ -z "$GOOGLE_ID" ]; then
    echo
    echo "  Connexion Google (facultatif — laissez vide pour l'ignorer)."
    demander "Google Client ID" "" GOOGLE_ID
    [ -n "$GOOGLE_ID" ] && demander_secret "Google Client Secret" GOOGLE_SECRET
fi

# Aucune adresse par défaut : celle qu'on proposait, admin@localhost, est
# refusée par scripts/admin-create.php. Validée d'un Entrée, elle faisait
# échouer la création du compte en toute fin d'installation, et l'écran
# « Bienvenue dans MIA » — ouvert à qui y arrive le premier — restait seul à
# pouvoir créer l'administrateur.
ADMIN_EMAIL=$(printf '%s' "${MIA_ADMIN_EMAIL:-}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
if [ "$INTERACTIF" -eq 1 ]; then
    while ! email_valide "$ADMIN_EMAIL"; do
        if [ -n "$ADMIN_EMAIL" ]; then
            alerte "adresse refusée : « $ADMIN_EMAIL ». Il faut une adresse complète, domaine compris : vous@exemple.fr"
        fi
        demander "E-mail du premier administrateur" "" ADMIN_EMAIL
    done
elif ! email_valide "$ADMIN_EMAIL"; then
    echo
    if [ -z "$ADMIN_EMAIL" ]; then
        echec "MIA_ADMIN_EMAIL manque : rien n'a été modifié."
        remede "Sans questions (--non-interactive, ou pas de terminal), l'adresse se donne d'avance."
    else
        echec "MIA_ADMIN_EMAIL refusée : « $ADMIN_EMAIL ». Rien n'a été modifié."
    fi
    remede "Une adresse complète, domaine compris : MIA_ADMIN_EMAIL=vous@exemple.fr ./install.sh"
    exit 1
fi
ADMIN_NOM="${MIA_ADMIN_NAME:-Administrateur}"

# ---------------------------------------------------------------- 3. Écriture du .env

titre "3. Génération de la configuration"

aleatoire() { # aleatoire <octets>
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex "$1"
    else
        od -An -tx1 -N"$1" /dev/urandom | tr -d ' \n'
    fi
}

# 8 caractères au moins : la règle de scripts/admin-create.php. Plus court, le
# compte n'était pas créé, comme avec une adresse refusée.
ADMIN_MDP="${MIA_ADMIN_PASSWORD:-}"
if [ -n "$ADMIN_MDP" ] && [ "${#ADMIN_MDP}" -lt 8 ]; then
    if [ "$INTERACTIF" -ne 1 ]; then
        echo
        echec "MIA_ADMIN_PASSWORD fait moins de 8 caractères : rien n'a été modifié."
        remede "Allongez-le, ou retirez la variable : un mot de passe sera tiré au hasard."
        exit 1
    fi
    alerte "MIA_ADMIN_PASSWORD fait moins de 8 caractères : il est ignoré"
    ADMIN_MDP=''
fi
if [ -z "$ADMIN_MDP" ] && [ "$INTERACTIF" -eq 1 ]; then
    while :; do
        demander_secret "Mot de passe de l'administrateur (8 caractères au moins, vide = tiré au hasard)" ADMIN_MDP
        if [ -z "$ADMIN_MDP" ] || [ "${#ADMIN_MDP}" -ge 8 ]; then break; fi
        alerte "trop court : 8 caractères au moins"
    done
fi
# Seul un mot de passe TIRÉ s'affiche au récapitulatif — un mot de passe saisi
# y apparaissait aussi, en clair.
if [ -z "$ADMIN_MDP" ]; then
    ADMIN_MDP=$(aleatoire 9)
    ADMIN_MDP_GENERE=1
else
    ADMIN_MDP_GENERE=0
fi

# Réinstallation (--force) : les secrets de l'ancien .env sont REPRIS, pas
# tirés à nouveau. La base embarquée a été créée avec DB_PASS et DB_ROOT_PASS —
# MySQL ne les lit qu'à la création de son volume — et ENCRYPTION_KEY chiffre
# les clés d'API en base. De nouveaux secrets fermaient la base (« Access
# denied ») et rendaient ces clés illisibles. Le reste — mode, adresse, port,
# Google — vient des réponses de cette exécution.
ancien() { # ancien <CLE> : sa valeur dans l'ancien .env, telle qu'écrite (dernière occurrence)
    [ -f .env ] || return 0
    sed -n "s/^[[:space:]]*$1[[:space:]]*=//p" .env | tail -n 1 | tr -d '\r'
}

reprendre() { # reprendre <CLE> <variable_resultat> : échoue si absente, vide ou factice
    valeur=$(ancien "$1")
    case $(printf '%s' "$valeur" | tr -d " \t\"'") in
        ''|change_me_32_chars_minimum_secret) return 1 ;;
    esac
    eval "$2=\$valeur"
    REPRIS="$REPRIS $1"
}

REPRIS=''
reprendre APP_SECRET APP_SECRET         || APP_SECRET=$(aleatoire 32)
reprendre ENCRYPTION_KEY ENCRYPTION_KEY || ENCRYPTION_KEY=$(aleatoire 32)
# Mots de passe MySQL : repris d'une base embarquée à une base embarquée
# seulement. Une base externe a les siens, demandés plus haut.
if [ "$DB_HOTE" = db ]; then
    case $(ancien DB_HOST | tr -d " \t\"'") in
        ''|db) reprendre DB_PASS DB_MDP || true
               reprendre DB_ROOT_PASS DB_MDP_ROOT || true ;;
    esac
fi
[ -z "$DB_MDP" ]      && DB_MDP=$(aleatoire 16)
[ -z "$DB_MDP_ROOT" ] && DB_MDP_ROOT=$(aleatoire 16)
[ -n "$REPRIS" ] && ok "secrets repris de l'ancien .env :$REPRIS"

if [ -f .env ]; then
    HORODATAGE=$(date +%Y%m%d_%H%M%S)
    cp .env ".env.bak.$HORODATAGE"
    alerte "ancien .env sauvegardé dans .env.bak.$HORODATAGE"
fi

# Écriture ATOMIQUE : une interruption ne peut pas laisser un .env à moitié
# écrit, que compose lirait ensuite silencieusement de travers.
cat > .env.tmp <<FIN
# Généré par install.sh — mode « $MODE ».
# Contient des secrets : ne le versionnez pas, et sauvegardez-le hors serveur.
# ENCRYPTION_KEY protège les clés API en base : la perdre les rend illisibles.

COMPOSE_FILE=$COMPOSE_FILE_VALEUR

APP_URL=$URL
APP_ENV=production
DOMAIN=$DOMAINE

APP_SECRET=$APP_SECRET
ENCRYPTION_KEY=$ENCRYPTION_KEY

DB_HOST=$DB_HOTE
DB_PORT=$DB_PORT_VALEUR
DB_NAME=$DB_NOM
DB_USER=$DB_UTILISATEUR
DB_PASS=$DB_MDP
DB_ROOT_PASS=$DB_MDP_ROOT

GOOGLE_CLIENT_ID=$GOOGLE_ID
GOOGLE_CLIENT_SECRET=$GOOGLE_SECRET
GOOGLE_REDIRECT_URI=$URL/auth/callback
GOOGLE_CLIENT_ID_IOS=

QDRANT_URL=
QDRANT_DATA_PATH=
FIN

if [ "$MODE" != "traefik" ]; then
    printf '\nAPP_BIND=%s\nAPP_PORT=%s\n' "$BIND" "$PORT" >> .env.tmp
fi
if [ "$MODE" = "proxy" ]; then
    printf '\n# Le proxy termine le TLS : autorise la dérivation du cookie Secure\n# depuis X-Forwarded-Proto. Ne mettez 1 QUE derrière un proxy de confiance.\nTRUSTED_PROXY=1\n' >> .env.tmp
fi

mv .env.tmp .env
chmod 600 .env
ok ".env écrit (mode $MODE)"

# ---------------------------------------------------------------- 4. Démarrage

titre "4. Construction et démarrage"

docker compose build
docker compose up -d
ok "conteneurs démarrés"

# ---------------------------------------------------------------- 5. Attente

titre "5. Attente de la mise en service"

sante() { docker compose exec -T app curl -fsS http://127.0.0.1/healthz 2>/dev/null; }

ATTENTE=0
PRET=0
while [ "$ATTENTE" -lt 180 ]; do
    if REPONSE=$(sante) && printf '%s' "$REPONSE" | grep -q '"status":"ok"'; then
        PRET=1
        break
    fi
    sleep 3
    ATTENTE=$((ATTENTE + 3))
    printf '\r  démarrage… %ss' "$ATTENTE"
done
printf '\r                              \r'

if [ "$PRET" -ne 1 ]; then
    echec "MIA n'a pas répondu au bout de 180 secondes."
    echo
    echo "  ---- 50 dernières lignes du conteneur app ----"
    docker compose logs --tail 50 app 2>&1 || true
    echo "  ---- 20 dernières lignes du worker ----"
    docker compose logs --tail 20 worker 2>&1 || true
    echo
    remede "Diagnostic détaillé : docker compose exec app php scripts/doctor.php"
    exit 1
fi
ok "l'application répond"

# Première vraie page : /healthz saute volontairement les semis paresseux (il
# est appelé toutes les 30 s par Docker). Sans cette visite, l'assistant d'aide
# « MIA : mode d'emploi » n'est pas encore créé et le diagnostic qui suit le
# signalerait comme manquant — un faux positif dès la fin de l'installation.
docker compose exec -T app curl -fsS -o /dev/null http://127.0.0.1/auth/login 2>/dev/null || true
ok "première page servie (semis de l'assistant d'aide lancé)"

# ---------------------------------------------------------------- 6. Vérification

titre "6. Vérification"

if docker compose exec -T app php scripts/doctor.php; then
    :
else
    alerte "des contrôles sont en échec (voir ci-dessus) — l'installation continue"
fi

titre "7. Compte administrateur"

# Code 2 : un compte existe déjà à cette adresse — réinstallation d'une MIA
# qui a des données. Il est laissé tel quel, rôle et mot de passe compris.
CODE_ADMIN=0
printf '%s' "$ADMIN_MDP" | docker compose exec -T app \
        php scripts/admin-create.php --email="$ADMIN_EMAIL" --name="$ADMIN_NOM" || CODE_ADMIN=$?

case "$CODE_ADMIN" in
    0) ;;
    2) ok "compte existant laissé tel quel, rôle et mot de passe compris"
       remede "Pour les redéfinir, nouveau mot de passe lu sur l'entrée standard :"
       remede "docker compose exec -T app php scripts/admin-create.php --email=$ADMIN_EMAIL --reset" ;;
    *) alerte "le compte n'a pas pu être créé (voir le message ci-dessus)"
       remede "Créez-le d'ici, mot de passe lu sur l'entrée standard :"
       remede "docker compose exec -T app php scripts/admin-create.php --email=$ADMIN_EMAIL"
       remede "ou sans attendre sur $URL : l'écran « Bienvenue dans MIA » le crée, pour qui y arrive le premier." ;;
esac

# ---------------------------------------------------------------- Récapitulatif

titre "MIA est installée"

echo "  Adresse      : $URL"
case "$CODE_ADMIN" in
    0) echo "  Identifiant  : $ADMIN_EMAIL"
       if [ "$ADMIN_MDP_GENERE" -eq 1 ]; then
           printf '  Mot de passe : %b%s%b   (affiché une seule fois)\n' "$B" "$ADMIN_MDP" "$Z"
       else
           echo "  Mot de passe : celui que vous avez saisi"
       fi ;;
    2) echo "  Identifiant  : $ADMIN_EMAIL (compte existant, mot de passe inchangé)" ;;
esac

cat <<'FIN'

  Il reste à faire, depuis l'interface d'administration :
    1. Clés API des modèles       Administration → Gestion LLMs → Clés API
                                  (sans elles, le chat ne peut rien répondre)
    2. Modèle par défaut          Administration → Gestion LLMs
    3. Outils image / vidéo       Administration → Outils          (facultatif)
    4. Nom, logo, couleurs        Administration → Paramètres      (facultatif)
    5. État de l'installation     Administration → Diagnostic

  Commandes utiles :
    docker compose ps                              état des services
    docker compose logs -f app                     journaux
    docker compose exec app php scripts/doctor.php diagnostic complet
    ./update.sh                                    mise à jour
    ./backup.sh                                    sauvegarde

  Sauvegardez le fichier .env HORS de ce serveur : il contient ENCRYPTION_KEY,
  sans laquelle les clés API enregistrées deviennent illisibles.
FIN
