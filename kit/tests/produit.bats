#!/usr/bin/env bats
# Vérifie le contrat de sauvegarde MIA avec Docker/Compose simulés.

setup() {
  KIT_DIR="$BATS_TEST_TMPDIR/kit"
  mkdir -p "$KIT_DIR"
  sed 's/\r$//' "$BATS_TEST_DIRNAME/../produit.sh" > "$KIT_DIR/produit.sh"
  KIT_ENV="$KIT_DIR/.env"
  printf 'ENCRYPTION_KEY=secret\nMIA_VERSION=1.6.0\n' > "$KIT_ENV"
  JOURNAL="$BATS_TEST_TMPDIR/appels.log"
  : > "$JOURNAL"
  export KIT_DIR KIT_ENV JOURNAL

  kit_compose() {
    echo "compose $*" >> "$JOURNAL"
    case "$*" in
      *"exec -T db"*)
        if [[ "$*" == *mysqldump* ]]; then
          printf '%s\n' '-- Dump completed on 2026-01-01 00:00:00'
        else
          cat >/dev/null
        fi
        ;;
      *"ps -q app"*) printf 'app-container\n' ;;
      *"ps -q qdrant"*) printf 'qdrant-container\n' ;;
      *"ps -aq app"*) printf 'app-container\n' ;;
      *"ps -aq qdrant"*) printf 'qdrant-container\n' ;;
    esac
  }
  docker() {
    echo "docker $*" >> "$JOURNAL"
    case "$*" in
      *"tar czf -"*) printf 'archive-content' ;;
      *"tar -tzf"*) return 0 ;;
      *"tar xzf"*) return 0 ;;
    esac
    return 0
  }
  export -f kit_compose docker
  source "$KIT_DIR/produit.sh"
}

@test "sauvegarde inclut dump MySQL, configuration, fichiers et stockage Qdrant" {
  dossier="$KIT_DIR/sauvegarde"
  mkdir "$dossier"
  produit_sauvegarder "$dossier"

  grep -q '^-- Dump completed on ' "$dossier/mysql.sql"
  [ -s "$dossier/env.sauvegarde" ]
  [ "$(stat -c '%a' "$dossier/env.sauvegarde")" = 600 ]
  [ -s "$dossier/data.tar.gz" ]
  [ -s "$dossier/qdrant-storage.tar.gz" ]
  grep -q 'mysqldump.*--add-drop-database --databases' "$JOURNAL"
  grep -q 'compose stop qdrant' "$JOURNAL"
  grep -q 'compose start qdrant' "$JOURNAL"
}

@test "restauration valide les sauvegardes avant de rétablir MySQL, fichiers et Qdrant" {
  dossier="$KIT_DIR/sauvegarde"
  mkdir "$dossier"
  produit_sauvegarder "$dossier"
  : > "$JOURNAL"

  produit_restaurer "$dossier"

  grep -q 'compose exec -T db sh -c exec mysql' "$JOURNAL"
  grep -q 'tar -tzf /backup/data.tar.gz' "$JOURNAL"
  grep -q 'tar -tzf /backup/qdrant-storage.tar.gz' "$JOURNAL"
  grep -q 'tar -tzf /backup/qdrant-current.tar.gz' "$JOURNAL"
  grep -q 'tar czf - -C /qdrant storage' "$JOURNAL"
  [ ! -e "$dossier/qdrant-current.tar.gz" ]
  grep -q 'compose stop qdrant' "$JOURNAL"
  grep -q 'compose start qdrant' "$JOURNAL"
}

@test "restauration refuse un dump MySQL incomplet avant toute modification" {
  dossier="$KIT_DIR/sauvegarde"
  mkdir "$dossier"
  printf 'incomplete dump\n' > "$dossier/mysql.sql"

  if produit_restaurer "$dossier"; then
    false
  fi
  ! grep -q 'compose exec -T db' "$JOURNAL"
}
