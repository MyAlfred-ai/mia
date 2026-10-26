#!/usr/bin/env bats
# Tests unitaires du moteur : les opérations Docker et les particularités
# produit sont simulées afin de vérifier les contrats de sécurité et de statut.

setup() {
  KIT_SRC="$BATS_TEST_DIRNAME/../lib/kit.sh"
  K="$BATS_TEST_TMPDIR/kit"
  FAUX="$BATS_TEST_TMPDIR/faux"
  JOURNAL="$BATS_TEST_TMPDIR/appels.log"
  mkdir -p "$K/lib" "$FAUX"
  sed 's/\r$//' "$KIT_SRC" > "$K/lib/kit.sh"
  : > "$JOURNAL"
  export JOURNAL
  cat > "$FAUX/docker" <<'EOF'
#!/usr/bin/env bash
echo "docker $*" >> "$JOURNAL"
case "$*" in
  *" pull"*) [ -z "${FAUX_PULL_ECHEC:-}" ] || exit 1 ;;
  *cosign*verify*) [ -z "${FAUX_COSIGN_ECHEC:-}" ] || exit 1 ;;
  *"ps -q app"*) echo app-container ;;
  *"ps -q worker"*) echo worker-container ;;
  *inspect*) echo healthy ;;
esac
exit 0
EOF
  printf '#!/bin/sh\necho "git $*" >> "$JOURNAL"\n' > "$FAUX/git"
  chmod +x "$FAUX/docker" "$FAUX/git"
  sed -i 's/\r$//' "$FAUX/docker" "$FAUX/git"
  export PATH="$FAUX:$PATH"
  cat > "$K/produit.sh" <<'EOF'
PRODUIT=mia-test
PRODUIT_DEPOT=MyAlfred-ai/mia
VERSION_VAR=MIA_VERSION
PRODUIT_IMAGES=mia
PRODUIT_VARIABLES="COMPOSE_PROJECT_NAME MIA_VERSION APP_URL APP_SECRET ENCRYPTION_KEY DB_NAME DB_USER DB_PASS DB_ROOT_PASS"
PRODUIT_TAILLE_MO=1000
produit_sauvegarder() { echo "sauvegarde $1" >> "$JOURNAL"; [ -z "${FAUX_SAUVEGARDE_ECHEC:-}" ] && : > "$1/mysql.sql" && : > "$1/env.sauvegarde"; }
produit_restaurer() { echo "restauration $1" >> "$JOURNAL"; [ -z "${FAUX_RESTAURATION_ECHEC:-}" ]; }
produit_sante() {
  local v; v=$(grep '^MIA_VERSION=' "$KIT_DIR/.env" | cut -d= -f2)
  case " ${FAUX_SANTE_KO:-} " in *" $v "*) return 1 ;; esac
  return 0
}
EOF
  sed -i 's/\r$//' "$K/produit.sh"
  cat > "$K/docker-compose.yml" <<'EOF'
services: {}
EOF
  printf 'COMPOSE_PROJECT_NAME=mia\nMIA_VERSION=1.6.0\nAPP_URL=https://mia.example.test\nAPP_SECRET=x\nENCRYPTION_KEY=y\nDB_NAME=mia\nDB_USER=mia\nDB_PASS=z\nDB_ROOT_PASS=q\n' > "$K/.env"
  printf '#!/usr/bin/env bash\nset -euo pipefail\nKIT_DIR="$(cd "$(dirname "$0")" && pwd)"\n. "$KIT_DIR/lib/kit.sh"\nkit_mettre_a_jour "$@"\n' > "$K/update.sh"
  printf '#!/usr/bin/env bash\nset -euo pipefail\nKIT_DIR="$(cd "$(dirname "$0")" && pwd)"\n. "$KIT_DIR/lib/kit.sh"\nkit_revenir "$@"\n' > "$K/rollback.sh"
  printf '#!/usr/bin/env bash\nset -euo pipefail\nKIT_DIR="$(cd "$(dirname "$0")" && pwd)"\n. "$KIT_DIR/lib/kit.sh"\nkit_demarrer\n' > "$K/start.sh"
  chmod +x "$K/update.sh" "$K/rollback.sh" "$K/start.sh"
  export KIT_ESPACE_LIBRE_MO=100000 SANTE_DELAI=0 SANTE_PAS=0
}

version() { grep '^MIA_VERSION=' "$K/.env" | cut -d= -f2; }

@test "mise à jour réussie écrit la version et note la précédente" {
  run "$K/update.sh" 1.6.1
  [ "$status" -eq 0 ]
  [ "$(version)" = 1.6.1 ]
  [ "$(cat "$K/.etat/version-precedente")" = 1.6.0 ]
  grep -q 'docker compose .* pull' "$JOURNAL"
  grep -q 'docker compose .* up -d' "$JOURNAL"
  [ "$(stat -c '%a' "$K/.env")" = 600 ]
}

@test "aucun appel à git" {
  run "$K/update.sh" 1.6.1
  [ "$status" -eq 0 ]
  ! grep -q '^git ' "$JOURNAL"
}

@test "démarre app avant worker afin de sérialiser les migrations initiales" {
  run "$K/start.sh"
  [ "$status" -eq 0 ]
  db=$(grep -n 'docker compose .*up -d db qdrant' "$JOURNAL" | cut -d: -f1)
  stop=$(grep -n 'docker compose .*stop worker$' "$JOURNAL" | cut -d: -f1)
  app=$(grep -n 'docker compose .*up -d app$' "$JOURNAL" | cut -d: -f1)
  worker=$(grep -n 'docker compose .*up -d worker$' "$JOURNAL" | cut -d: -f1)
  [ "$stop" -lt "$db" ]
  [ "$db" -lt "$app" ]
  [ "$app" -lt "$worker" ]
}

@test "variable obligatoire absente refuse avant Docker" {
  sed -i '/^APP_SECRET=/d' "$K/.env"
  run "$K/update.sh" 1.6.1
  [ "$status" -eq 1 ]
  [[ "$output" == *APP_SECRET* ]]
  ! grep -q '^docker ' "$JOURNAL"
}

@test "version mal formée refuse avant Docker" {
  for v in main latest v1.6.1 1.6; do
    run "$K/update.sh" "$v"
    [ "$status" -eq 1 ]
  done
  ! grep -q '^docker ' "$JOURNAL"
}

@test "espace disque insuffisant refuse sans pull ni changement du env" {
  KIT_ESPACE_LIBRE_MO=500 run "$K/update.sh" 1.6.1
  [ "$status" -eq 1 ]
  [[ "$output" == *disque* ]]
  ! grep -q ' pull' "$JOURNAL"
  [ "$(version)" = 1.6.0 ]
}

@test "échec de sauvegarde refuse avant pull" {
  FAUX_SAUVEGARDE_ECHEC=1 run "$K/update.sh" 1.6.1
  [ "$status" -eq 1 ]
  ! grep -q ' pull' "$JOURNAL"
  [ "$(version)" = 1.6.0 ]
}

@test "signature refusée laisse la configuration inchangée" {
  FAUX_COSIGN_ECHEC=1 run "$K/update.sh" 1.6.1
  [ "$status" -eq 1 ]
  [[ "$output" == *signature* ]]
  ! grep -q ' pull' "$JOURNAL"
  ! grep -q ' up -d' "$JOURNAL"
  [ "$(version)" = 1.6.0 ]
}

@test "identité de signature de release cible le dépôt et le workflow" {
  run "$K/update.sh" 1.6.1
  [ "$status" -eq 0 ]
  grep -q 'cosign.*verify.*github.com/MyAlfred-ai/mia/.github/workflows/release.yml@refs/tags/v.*ghcr.io/myalfred-ai/mia:1.6.1' "$JOURNAL"
}

@test "edge cible le workflow develop" {
  run "$K/update.sh" edge
  [ "$status" -eq 0 ]
  grep -q 'workflows/edge.yml@refs/heads/develop.*ghcr.io/myalfred-ai/mia:edge' "$JOURNAL"
}

@test "échec de santé restaure la version et retourne 1" {
  FAUX_SANTE_KO=1.6.1 run "$K/update.sh" 1.6.1
  [ "$status" -eq 1 ]
  [ "$(version)" = 1.6.0 ]
  grep -q '^restauration ' "$JOURNAL"
}

@test "échec du retour arrière retourne 2" {
  FAUX_SANTE_KO="1.6.1 1.6.0" run "$K/update.sh" 1.6.1
  [ "$status" -eq 2 ]
}

@test "STATUS_FILE reçoit le code de sortie" {
  STATUS_FILE="$BATS_TEST_TMPDIR/status" run "$K/update.sh" 1.6.1
  [ "$(cat "$BATS_TEST_TMPDIR/status")" = 0 ]
  FAUX_SANTE_KO=1.6.2 STATUS_FILE="$BATS_TEST_TMPDIR/status" run "$K/update.sh" 1.6.2
  [ "$(cat "$BATS_TEST_TMPDIR/status")" = 1 ]
}

@test "rollback restaure la version précédente connue" {
  run "$K/update.sh" 1.6.1
  [ "$status" -eq 0 ]
  run "$K/rollback.sh"
  [ "$status" -eq 0 ]
  [ "$(version)" = 1.6.0 ]
  grep -q '^restauration ' "$JOURNAL"
}

@test "COMPOSE_FILE sélectionne les fichiers de surcharge attendus" {
  cp "$K/docker-compose.yml" "$K/docker-compose.proxy.yml"
  printf 'COMPOSE_FILE=docker-compose.proxy.yml\n' >> "$K/.env"
  run "$K/update.sh" 1.6.1
  [ "$status" -eq 0 ]
  grep -q -- "-f $K/docker-compose.proxy.yml" "$JOURNAL"
}
