#!/usr/bin/env bats
# Parcours d'installation non interactif avec Docker simulé ; aucun conteneur
# ou service externe n'est lancé.

setup() {
  K="$BATS_TEST_TMPDIR/kit"
  FAUX="$BATS_TEST_TMPDIR/faux"
  JOURNAL="$BATS_TEST_TMPDIR/appels.log"
  mkdir -p "$K/kit" "$FAUX"
  cp -R "$BATS_TEST_DIRNAME/../." "$K/kit/"
  K="$K/kit"
  find "$K" -type f -name '*.sh' -exec sed -i 's/\r$//' {} \;
  : > "$JOURNAL"
  export JOURNAL

  cat > "$FAUX/docker" <<'EOF'
#!/usr/bin/env bash
echo "docker $*" >> "$JOURNAL"
case "$*" in
  *cosign*verify*) [ -z "${FAUX_COSIGN_ECHEC:-}" ] || exit 1 ;;
  *"ps -q app"*) printf 'app-container\n' ;;
  *"ps -q worker"*) printf 'worker-1\nworker-2\nworker-3\nworker-4\n' ;;
  *"inspect -f"*) printf 'healthy\n' ;;
  *"exec -T app php scripts/admin-create.php"*) cat >/dev/null ;;
esac
exit 0
EOF
  chmod +x "$FAUX/docker"
  sed -i 's/\r$//' "$FAUX/docker"
  cat > "$FAUX/openssl" <<'EOF'
#!/bin/sh
printf '%*s\n' "$(($3 * 2))" '' | tr ' ' a
EOF
  chmod +x "$FAUX/openssl"
  export PATH="$FAUX:$PATH"
}

install_cmd() {
  env "$@" KIT_ESPACE_LIBRE_MO=10000 \
    APP_URL=https://mia.example.test \
    MIA_ADMIN_PASSWORD=supersecure \
    bash "$K/install.sh"
}

@test "installation crée les secrets, vérifie les signatures, pull les images et crée l'admin" {
  run install_cmd MIA_ADMIN_EMAIL=admin@example.test
  [ "$status" -eq 0 ]
  [ -f "$K/.env" ]
  [ "$(stat -c '%a' "$K/.env")" = 600 ]
  [ "$(grep '^APP_SECRET=' "$K/.env" | cut -d= -f2 | wc -c)" -eq 65 ]
  grep -q 'cosign.*verify.*ghcr.io/myalfred-ai/mia:1.6.0' "$JOURNAL"
  grep -q 'docker compose .* pull' "$JOURNAL"
  grep -q 'docker compose .* up -d db qdrant' "$JOURNAL"
  grep -q 'docker compose .* up -d app' "$JOURNAL"
  grep -q 'docker compose .* up -d worker' "$JOURNAL"
  grep -q 'scripts/admin-create.php --email=admin@example.test' "$JOURNAL"
  ! grep -q 'docker compose .* build' "$JOURNAL"
  [ "$(grep -n 'cosign.*verify' "$JOURNAL" | cut -d: -f1)" -lt "$(grep -n ' pull' "$JOURNAL" | cut -d: -f1)" ]
  [ "$(grep -n ' up -d db qdrant' "$JOURNAL" | cut -d: -f1)" -lt "$(grep -n ' up -d app' "$JOURNAL" | cut -d: -f1)" ]
  [ "$(grep -n ' up -d app' "$JOURNAL" | cut -d: -f1)" -lt "$(grep -n ' up -d worker' "$JOURNAL" | cut -d: -f1)" ]
}

@test "signature refusée arrête l'installation avant pull ou démarrage" {
  run install_cmd FAUX_COSIGN_ECHEC=1 MIA_ADMIN_EMAIL=admin@example.test
  [ "$status" -eq 1 ]
  [[ "$output" == *signature* ]]
  ! grep -q ' pull' "$JOURNAL"
  ! grep -q ' up -d' "$JOURNAL"
}

@test "e-mail admin absent refuse avant de créer le fichier .env" {
  run install_cmd -u MIA_ADMIN_EMAIL
  [ "$status" -eq 1 ]
  [[ "$output" == *"e-mail administrateur"* ]]
  [ ! -e "$K/.env" ]
}
