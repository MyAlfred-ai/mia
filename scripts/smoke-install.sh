#!/bin/sh
# =============================================================================
# Installation de bout en bout, vérifiée : le chemin qui casse le plus
# silencieusement, parce que personne ne le rejoue au quotidien.
#
#   ./scripts/smoke-install.sh
#
# Part d'un dépôt SANS .env, installe, et vérifie que l'application est
# réellement utilisable : elle répond, son diagnostic est propre, on peut se
# connecter, et le worker a la même configuration que l'app. Vérifie aussi
# qu'une adresse d'administrateur refusée arrête l'installeur avant toute
# écriture, et qu'une réinstallation (--force) garde secrets, base et compte.
#
# Démonte tout à la fin, y compris en cas d'échec. À lancer dans un
# environnement jetable (CI, machine virtuelle) : il SUPPRIME le .env existant,
# ses sauvegardes .env.bak.* et les volumes du projet.
# =============================================================================

set -e

cd "$(dirname "$0")/.."

PORT="${SMOKE_PORT:-8099}"
URL="http://127.0.0.1:$PORT"
EMAIL="smoke@exemple.test"
MDP="MotDePasseSmoke123"
CODE_SORTIE=1

etape() { printf '\n=== %s\n' "$1"; }
mort()  { printf '\nÉCHEC : %s\n' "$1" >&2; exit 1; }

demonter() {
    if [ "$CODE_SORTIE" -ne 0 ]; then
        etape "Journaux des conteneurs avant démontage"
        for service in app worker db; do
            printf '%s\n' "---- $service ----"
            docker compose logs --tail 100 "$service" || true
        done
    fi
    etape "Démontage"
    docker compose down -v >/dev/null 2>&1 || true
    rm -f .env .env.bak.*
    exit "$CODE_SORTIE"
}
trap demonter EXIT INT TERM

rm -f .env

etape "Une adresse d'administrateur refusée arrête l'installeur, sans rien écrire"
# L'installeur proposait admin@localhost, que scripts/admin-create.php refuse :
# le compte n'était pas créé, en toute fin d'installation.
for adresse in admin@localhost ''; do
    if MIA_MODE=standalone MIA_PORT="$PORT" MIA_URL="$URL" MIA_ADMIN_EMAIL="$adresse" \
            ./install.sh --non-interactive > /tmp/smoke-refus.log 2>&1; then
        mort "adresse « $adresse » acceptée"
    fi
    grep -q 'MIA_ADMIN_EMAIL' /tmp/smoke-refus.log || mort "adresse « $adresse » refusée sans explication"
    [ ! -f .env ] || mort "adresse « $adresse » refusée, mais un .env a été écrit"
done

etape "Installation depuis un dépôt vierge"
MIA_MODE=standalone \
MIA_PORT="$PORT" \
MIA_URL="$URL" \
MIA_ADMIN_EMAIL="$EMAIL" \
MIA_ADMIN_NAME="Compte de vérification" \
MIA_ADMIN_PASSWORD="$MDP" \
    ./install.sh --non-interactive || mort "install.sh s'est arrêté en erreur"

etape "La sonde /healthz répond « ok »"
SANTE=$(curl -fsS "$URL/healthz") || mort "/healthz ne répond pas"
printf '%s\n' "$SANTE"
printf '%s' "$SANTE" | grep -q '"status":"ok"'          || mort "statut différent de ok"
printf '%s' "$SANTE" | grep -q '"migrations":"ok"'      || mort "migrations non appliquées"
printf '%s' "$SANTE" | grep -q '"database":"ok"'        || mort "base injoignable"

etape "Aucun contrôle en échec (les avertissements sont normaux sans clé API)"
docker compose exec -T app php scripts/doctor.php --quick \
    || mort "doctor.php signale au moins un contrôle en échec"

etape "La page de connexion porte un jeton CSRF"
curl -fsS -c /tmp/smoke-cookies "$URL/auth/login" > /tmp/smoke-login.html \
    || mort "la page de connexion ne répond pas"
JETON=$(grep -oE 'name="csrf_token" value="[0-9a-f]{64}"' /tmp/smoke-login.html \
        | head -n1 | grep -oE '[0-9a-f]{64}')
[ -n "$JETON" ] || mort "aucun jeton CSRF dans la page de connexion"

etape "Connexion avec le compte créé par l'installeur"
STATUT=$(curl -s -o /dev/null -w '%{http_code}' -b /tmp/smoke-cookies -c /tmp/smoke-cookies \
    -d "email=$EMAIL" -d "password=$MDP" -d "csrf_token=$JETON" "$URL/auth/login")
[ "$STATUT" = "302" ] || mort "la connexion a répondu $STATUT au lieu de 302"

STATUT=$(curl -s -o /dev/null -w '%{http_code}' -b /tmp/smoke-cookies "$URL/admin/diagnostics")
[ "$STATUT" = "200" ] || mort "la page de diagnostic a répondu $STATUT au lieu de 200"

etape "Le worker a la même configuration que l'application"
# Sans GOOGLE_CLIENT_ID (cas de la CI), le worker annonce légitimement une
# configuration incomplète. Ce qu'on vérifie ici, c'est qu'il a DÉMARRÉ et
# qu'il a pu joindre la base — un worker muet est le symptôme d'une image
# périmée ou d'un bloc d'environnement divergent.
docker compose logs worker 2>&1 | grep -q '\[worker\] démarré' \
    || mort "le worker n'a pas démarré"

etape "Les conteneurs sont déclarés sains par Docker"
# On attend la CONVERGENCE : se contenter d'accepter « starting » ne vérifierait
# rien du tout, puisque c'est l'état de tout conteneur qui vient de démarrer.
# Le worker, en particulier, n'est déclaré sain qu'après un battement de cœur
# portant la bonne version — c'est précisément ce qu'on veut prouver.
for service in app worker; do
    ATTENTE=0
    ETAT=inconnu
    while [ "$ATTENTE" -lt 180 ]; do
        CONTENEURS=$(docker compose ps -q "$service") \
            || mort "impossible de trouver les conteneurs $service"
        [ -n "$CONTENEURS" ] || mort "$service n'a aucun conteneur"

        ETAT=healthy
        for conteneur in $CONTENEURS; do
            SANTE=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}absent{{end}}' \
                    "$conteneur") \
                || mort "impossible de lire l'état de santé de $service ($conteneur)"
            case "$SANTE" in
                healthy) ;;
                starting) ETAT=starting ;;
                unhealthy) mort "$service est déclaré malade — docker compose logs $service" ;;
                absent) mort "$service n'a aucun HEALTHCHECK : la régression ne serait plus détectée" ;;
                *) ETAT="$SANTE" ;;
            esac
        done
        [ "$ETAT" = "healthy" ] && break
        sleep 5
        ATTENTE=$((ATTENTE + 5))
    done
    printf '  %-8s %s (après %ss)\n' "$service" "$ETAT" "$ATTENTE"
    [ "$ETAT" = "healthy" ] || mort "$service n'est jamais devenu sain (dernier état : $ETAT)"
done

etape "Réinstallation (--force), MIA en service : secrets repris, base et compte intacts"
# --force tirait de nouveaux secrets : la base embarquée refusait la connexion
# (« Access denied ») et les clés d'API enregistrées devenaient illisibles. Le
# mot de passe donné ici est volontairement AUTRE : le compte existant doit
# rester tel quel.
grep -E '^(APP_SECRET|ENCRYPTION_KEY|DB_PASS|DB_ROOT_PASS)=' .env > /tmp/smoke-secrets
MIA_MODE=standalone \
MIA_PORT="$PORT" \
MIA_URL="$URL" \
MIA_ADMIN_EMAIL="$EMAIL" \
MIA_ADMIN_PASSWORD="UnAutreMotDePasse456" \
    ./install.sh --force --non-interactive || mort "install.sh --force s'est arrêté en erreur"
grep -E '^(APP_SECRET|ENCRYPTION_KEY|DB_PASS|DB_ROOT_PASS)=' .env | cmp -s - /tmp/smoke-secrets \
    || mort "--force a changé les secrets du .env"
curl -fsS "$URL/healthz" | grep -q '"database":"ok"' || mort "base injoignable après --force"

rm -f /tmp/smoke-cookies
curl -fsS -c /tmp/smoke-cookies "$URL/auth/login" > /tmp/smoke-login.html \
    || mort "la page de connexion ne répond pas après --force"
JETON=$(grep -oE 'name="csrf_token" value="[0-9a-f]{64}"' /tmp/smoke-login.html \
        | head -n1 | grep -oE '[0-9a-f]{64}') || mort "aucun jeton CSRF dans la page de connexion après --force"
curl -s -o /dev/null -b /tmp/smoke-cookies -c /tmp/smoke-cookies \
    -d "email=$EMAIL" -d "password=$MDP" -d "csrf_token=$JETON" "$URL/auth/login"
# Une connexion refusée répond aussi 302 : c'est l'accès à l'administration qui
# prouve que l'ANCIEN mot de passe ouvre toujours le compte.
STATUT=$(curl -s -o /dev/null -w '%{http_code}' -b /tmp/smoke-cookies "$URL/admin/diagnostics")
[ "$STATUT" = "200" ] || mort "après --force, l'ancien mot de passe n'ouvre plus l'administration ($STATUT)"

printf '\nInstallation vérifiée de bout en bout.\n'
CODE_SORTIE=0
