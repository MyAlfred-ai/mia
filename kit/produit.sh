#!/usr/bin/env bash
# Particularités de MIA pour le moteur du kit d'installation.
# shellcheck disable=SC2034

PRODUIT=mia
PRODUIT_DEPOT=MyAlfred-ai/mia
VERSION_VAR=MIA_VERSION
PRODUIT_IMAGES="mia"
PRODUIT_VARIABLES="COMPOSE_PROJECT_NAME MIA_VERSION APP_URL APP_SECRET ENCRYPTION_KEY DB_NAME DB_USER DB_PASS DB_ROOT_PASS"
PRODUIT_TAILLE_MO=3000

produit_sauvegarder() {
  local dossier=$1 app_cid qdrant_cid chemins
  kit_compose exec -T db sh -c \
    'exec mysqldump --single-transaction --routines --triggers --no-tablespaces --add-drop-database --databases -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    > "$dossier/mysql.sql" || return 1
  [ -s "$dossier/mysql.sql" ] || return 1

  cp "$KIT_ENV" "$dossier/env.sauvegarde" && chmod 600 "$dossier/env.sauvegarde" || return 1

  app_cid=$(kit_compose ps -q app 2>/dev/null) || return 1
  [ -n "$app_cid" ] || return 1
  chemins='/var/www/html/storage/uploads /var/www/html/storage/profile_documents /var/www/html/storage/knowledge_bases /var/www/html/storage/branding /var/www/html/public/assets/img/generated /var/www/html/public/assets/img/branding /var/www/html/public/assets/img/avatars /var/www/html/public/assets/img/profils /var/www/html/public/assets/video/generated /var/www/html/public/downloads'
  docker run --rm --volumes-from "$app_cid" alpine:3.22 \
    sh -c "tar czf - $chemins" > "$dossier/data.tar.gz" || return 1
  [ -s "$dossier/data.tar.gz" ] || return 1

  qdrant_cid=$(kit_compose ps -q qdrant 2>/dev/null) || return 1
  [ -n "$qdrant_cid" ] || return 1
  kit_compose stop qdrant || return 1
  if ! docker run --rm --volumes-from "$qdrant_cid" alpine:3.22 \
      sh -c 'tar czf - -C /qdrant storage' > "$dossier/qdrant-storage.tar.gz"; then
    if ! kit_compose start qdrant; then
      kit_erreur "échec de la sauvegarde Qdrant et du redémarrage du service"
    fi
    return 1
  fi
  kit_compose start qdrant || { kit_erreur "Qdrant n'a pas pu redémarrer après la sauvegarde"; return 1; }
  [ -s "$dossier/qdrant-storage.tar.gz" ]
}

produit_restaurer() {
  local dossier=$1 app_cid qdrant_cid
  [ -s "$dossier/mysql.sql" ] || return 1
  grep -q '^-- Dump completed on ' "$dossier/mysql.sql" || return 1
  kit_compose exec -T db sh -c \
    'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD"' \
    < "$dossier/mysql.sql" || return 1

  app_cid=$(kit_compose ps -aq app 2>/dev/null | head -n 1)
  [ -n "$app_cid" ] || return 1
  [ -s "$dossier/data.tar.gz" ] || return 1
  docker run --rm -v "$dossier:/backup:ro" alpine:3.22 \
    tar -tzf /backup/data.tar.gz >/dev/null || return 1
  docker run --rm --volumes-from "$app_cid" -v "$dossier:/backup:ro" alpine:3.22 \
    sh -c 'tar xzf /backup/data.tar.gz -C /' || return 1

  qdrant_cid=$(kit_compose ps -aq qdrant 2>/dev/null | head -n 1)
  [ -n "$qdrant_cid" ] || return 1
  [ -s "$dossier/qdrant-storage.tar.gz" ] || return 1
  docker run --rm -v "$dossier:/backup:ro" alpine:3.22 \
    tar -tzf /backup/qdrant-storage.tar.gz >/dev/null || return 1
  kit_compose stop qdrant || return 1
  if ! docker run --rm --volumes-from "$qdrant_cid" alpine:3.22 \
      sh -c 'tar czf - -C /qdrant storage' > "$dossier/qdrant-current.tar.gz" \
      || ! docker run --rm -v "$dossier:/backup:ro" alpine:3.22 \
        tar -tzf /backup/qdrant-current.tar.gz >/dev/null; then
    rm -f "$dossier/qdrant-current.tar.gz"
    kit_compose start qdrant || kit_erreur "Qdrant n'a pas pu redémarrer après la sauvegarde de sécurité"
    return 1
  fi
  if ! docker run --rm --volumes-from "$qdrant_cid" -v "$dossier:/backup:ro" alpine:3.22 \
      sh -c 'set -eu; find /qdrant/storage -mindepth 1 -maxdepth 1 -exec rm -rf {} \;; tar xzf /backup/qdrant-storage.tar.gz -C /qdrant; test -d /qdrant/storage'; then
    if ! docker run --rm --volumes-from "$qdrant_cid" -v "$dossier:/backup:ro" alpine:3.22 \
        sh -c 'set -eu; find /qdrant/storage -mindepth 1 -maxdepth 1 -exec rm -rf {} \;; tar xzf /backup/qdrant-current.tar.gz -C /qdrant; test -d /qdrant/storage'; then
      kit_erreur "échec de restauration Qdrant et de rétablissement de son état précédent"
    fi
    if ! kit_compose start qdrant; then
      kit_erreur "Qdrant n'a pas pu redémarrer après la restauration de secours"
    fi
    return 1
  fi
  kit_compose start qdrant || { kit_erreur "Qdrant n'a pas pu redémarrer après la restauration"; return 1; }
  rm -f "$dossier/qdrant-current.tar.gz"
}

produit_sante() {
  local cid cids
  cid=$(kit_compose ps -q app 2>/dev/null) || return 1
  [ -n "$cid" ] && [ "$(docker inspect -f '{{.State.Health.Status}}' "$cid" 2>/dev/null)" = healthy ] || return 1
  cids=$(kit_compose ps -q worker 2>/dev/null) || return 1
  [ -n "$cids" ] || return 1
  while IFS= read -r cid; do
    [ -z "$cid" ] || [ "$(docker inspect -f '{{.State.Health.Status}}' "$cid" 2>/dev/null)" = healthy ] || return 1
  done <<< "$cids"
}
