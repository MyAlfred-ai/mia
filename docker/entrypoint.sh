#!/bin/sh
set -e

# Permissions des dossiers de stockage montés en volumes. Un volume nommé NEUF
# est initialisé root:root si son dossier n'existe pas dans l'image au premier
# montage : www-data ne peut alors pas y créer de sous-dossier (ex.
# storage/knowledge_bases/{id}) → upload en échec (« Impossible de créer le
# dossier… »). Docker ne re-propage jamais les permissions d'un volume déjà
# créé : ce chown au boot (root ici, avant le drop vers www-data d'Apache) est
# le seul moyen de réparer un volume existant. Idempotent, non récursif (seul le
# dossier racine doit être inscriptible ; son contenu est déjà en www-data).
echo "[entrypoint] Permissions des dossiers de stockage..."
for d in uploads logs cache ratelimit profile_documents knowledge_bases branding; do
    mkdir -p "/var/www/html/storage/$d"
    chown www-data:www-data "/var/www/html/storage/$d"
    chmod 775 "/var/www/html/storage/$d"
done

# Attente BORNÉE. Une boucle infinie laissait le conteneur « Up » sans jamais
# servir une page : `docker compose ps` au vert, l'application injoignable, et
# aucun message expliquant qu'on attend une base qui ne viendra pas (mauvais
# DB_HOST, mot de passe refusé, service db absent du montage compose).
echo "[entrypoint] Attente de MySQL sur ${DB_HOST}:${DB_PORT}..."
ESSAI=0
MAX_ESSAIS=60          # 60 × 2 s = 2 minutes
while ! mysqladmin ping -h "$DB_HOST" -P "$DB_PORT" --silent 2>/dev/null; do
    ESSAI=$((ESSAI + 1))
    if [ "$ESSAI" -ge "$MAX_ESSAIS" ]; then
        echo "[entrypoint] ÉCHEC : MySQL n'a pas répondu sur ${DB_HOST}:${DB_PORT} en $((MAX_ESSAIS * 2)) s." >&2
        echo "[entrypoint] Vérifiez DB_HOST et DB_PORT dans le .env, que le service de base est" >&2
        echo "[entrypoint] démarré (docker compose ps), et ses journaux (docker compose logs db)." >&2
        echo "[entrypoint] Base externe : docker/compose/db.yml doit être retiré de COMPOSE_FILE." >&2
        exit 1
    fi
    sleep 2
done
echo "[entrypoint] MySQL prêt."

echo "[entrypoint] Lancement des migrations..."
php /var/www/html/docker/migrate.php
echo "[entrypoint] Migrations terminées."

# Avatars assistants par défaut : semés dans le volume profils/ depuis la copie
# bakée profils-defaults/. -n : ne jamais écraser un fichier déjà présent (donc
# les uploads et les renommages utilisateurs sont préservés).
echo "[entrypoint] Semis des avatars assistants par défaut..."
mkdir -p /var/www/html/public/assets/img/profils
cp -n /var/www/html/public/assets/img/profils-defaults/*.png \
      /var/www/html/public/assets/img/profils/ 2>/dev/null || true
chown -R www-data:www-data /var/www/html/public/assets/img/profils 2>/dev/null || true

# Diagnostic persistance des dossiers d'upload (volumes Docker).
# Les avatars/logos uploadés "disparaissent" au redeploy dans deux cas :
#   1. le dossier n'est PAS monté sur un volume → contenu éphémère, perdu au
#      rebuild de l'image (cf. fix 03af592 qui a ajouté profile_avatars) ;
#   2. le volume a été supprimé HORS pipeline (docker compose down -v, ou un
#      `docker volume prune` / `docker system prune --volumes` de nettoyage).
# update.sh ne fait jamais ça : une perte récurrente pointe donc vers l'un de
# ces deux cas. On journalise l'état de montage + le nombre de fichiers à
# chaque boot : une perte silencieuse devient un diagnostic en une ligne
# (logs persistés dans le volume storage_logs + visibles via `docker logs`).
echo "[entrypoint] Diagnostic persistance des dossiers d'upload..."
DIAG_LOG=/var/www/html/storage/logs/volumes-boot.log
mkdir -p /var/www/html/storage/logs 2>/dev/null || true
{
    echo "===== boot $(date -u '+%Y-%m-%dT%H:%M:%SZ') ====="
    for d in \
        public/assets/img/profils \
        public/assets/img/avatars \
        public/assets/img/branding \
        public/assets/img/generated \
        public/assets/video/generated \
        storage/uploads \
        storage/profile_documents \
        storage/knowledge_bases \
        storage/branding; do
        p="/var/www/html/$d"
        if grep -q " $p " /proc/mounts 2>/dev/null; then
            mnt="volume OK"
        else
            mnt="*** NON MONTÉ — éphémère, perte au redeploy ***"
        fi
        n=$(find "$p" -type f 2>/dev/null | wc -l | tr -d ' ')
        printf '  %-32s %s — %s fichier(s)\n' "$d" "$mnt" "$n"
    done
} 2>&1 | tee -a "$DIAG_LOG"

# Commande explicite (conteneur `worker` : `php scripts/worker.php`) : on lui
# passe la main au lieu de démarrer Apache. Exécutée en www-data — les fichiers
# qu'elle produit (images générées) sont servis par Apache sous cette identité ;
# écrits en root, ils seraient ensuite impossibles à remplacer ou supprimer.
if [ "$#" -gt 0 ]; then
    echo "[entrypoint] Démarrage : $*"
    exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
fi

# Conteneur `app` : la version servie est publiée dès le démarrage, pas à la
# première page — le worker s'y compare pour savoir s'il est à jour (cf.
# scripts/publish_version.php). Best-effort, jamais bloquant.
setpriv --reuid=www-data --regid=www-data --init-groups php /var/www/html/scripts/publish_version.php || true

echo "[entrypoint] Démarrage Apache..."
exec apache2-foreground
