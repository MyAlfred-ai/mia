#!/usr/bin/env bash
# Moteur du kit d'installation MIA. Les sauvegardes produit sont définies dans
# produit.sh ; ce fichier orchestre préflight, signature et retour arrière.

umask 077
: "${KIT_DIR:?KIT_DIR doit être défini avant de sourcer kit.sh}"
# shellcheck source=/dev/null
. "$KIT_DIR/produit.sh"

KIT_ENV="$KIT_DIR/.env"
KIT_ETAT="$KIT_DIR/.etat"
COSIGN_IMAGE="gcr.io/projectsigstore/cosign:v2.4.1"
OIDC_GITHUB="https://token.actions.githubusercontent.com"

kit_dire()   { echo "[$PRODUIT] $*"; }
kit_erreur() { echo "[$PRODUIT] ÉCHEC : $*" >&2; }

kit_lire() {
  grep -E "^$1=" "$KIT_ENV" 2>/dev/null | tail -n 1 | cut -d= -f2- || true
}

kit_ecrire() {
  local tmp="$KIT_ENV.tmp"
  awk -v k="$1" -v v="$2" 'BEGIN{f=0} $0 ~ "^"k"=" {print k"="v; f=1; next} {print} END{if(!f) print k"="v}' \
    "$KIT_ENV" > "$tmp" && chmod 600 "$tmp" && mv "$tmp" "$KIT_ENV"
}

kit_compose() {
  local fichiers=() liste f chemin noms=()
  liste=$(kit_lire COMPOSE_FILE)
  if [ -n "$liste" ]; then
    IFS=: read -r -a noms <<< "$liste"
    for f in "${noms[@]}"; do
      chemin=$f
      [[ "$chemin" = /* ]] || chemin="$KIT_DIR/$chemin"
      [ -f "$chemin" ] || { kit_erreur "fichier Compose absent : $f"; return 1; }
      fichiers+=(-f "$chemin")
    done
  else
    fichiers=(-f "$KIT_DIR/docker-compose.yml")
    [ ! -f "$KIT_DIR/docker-compose.override.yml" ] || fichiers+=(-f "$KIT_DIR/docker-compose.override.yml")
  fi
  docker compose --project-directory "$KIT_DIR" --env-file "$KIT_ENV" "${fichiers[@]}" "$@"
}

kit_version_valide() { [ "$1" = edge ] || [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; }

kit_verifier_variables() {
  local v manquantes=()
  [ -f "$KIT_ENV" ] || { kit_erreur ".env introuvable dans $KIT_DIR"; return 1; }
  for v in $PRODUIT_VARIABLES; do
    [ -n "$(kit_lire "$v")" ] || manquantes+=("$v")
  done
  if [ "${#manquantes[@]}" -gt 0 ]; then
    kit_erreur "variables obligatoires absentes du .env : ${manquantes[*]}"
    return 1
  fi
}

kit_verifier_disque() {
  local libre besoin
  libre=${KIT_ESPACE_LIBRE_MO:-$(df -Pm "$KIT_DIR" | awk 'NR==2 {print $4}')}
  [[ "$libre" =~ ^[0-9]+$ ]] || { kit_erreur "impossible de déterminer l'espace disque libre"; return 1; }
  besoin=$((PRODUIT_TAILLE_MO * 12 / 10))
  if [ "$libre" -lt "$besoin" ]; then
    kit_erreur "espace disque insuffisant : $libre Mo libres, $besoin Mo nécessaires (images + sauvegarde + 20 %)"
    return 1
  fi
}

kit_verifier_signatures() {
  local version=$1 identite img
  if [ "$version" = edge ]; then
    identite="^https://github.com/$PRODUIT_DEPOT/.github/workflows/edge.yml@refs/heads/develop$"
  else
    identite="^https://github.com/$PRODUIT_DEPOT/.github/workflows/release.yml@refs/tags/v[0-9]+\\.[0-9]+\\.[0-9]+(-[0-9A-Za-z.-]+)?$"
  fi
  for img in $PRODUIT_IMAGES; do
    if ! docker run --rm "$COSIGN_IMAGE" verify \
        --certificate-identity-regexp "$identite" \
        --certificate-oidc-issuer "$OIDC_GITHUB" \
        "ghcr.io/myalfred-ai/$img:$version" >/dev/null; then
      kit_erreur "signature invalide ou absente pour $img:$version"
      return 1
    fi
  done
  kit_dire "signature vérifiée."
}

kit_attendre_sante() {
  local fin=$((SECONDS + ${SANTE_DELAI:-150}))
  while :; do
    produit_sante && return 0
    [ "$SECONDS" -ge "$fin" ] && return 1
    sleep "${SANTE_PAS:-5}"
  done
}

kit_attendre_app_saine() {
  local fin=$((SECONDS + ${SANTE_DELAI:-150})) cid etat
  while :; do
    cid=$(kit_compose ps -q app 2>/dev/null) || cid=""
    etat=""
    [ -z "$cid" ] || etat=$(docker inspect -f '{{.State.Health.Status}}' "$cid" 2>/dev/null || true)
    [ "$etat" = healthy ] && return 0
    [ "$SECONDS" -ge "$fin" ] && return 1
    sleep "${SANTE_PAS:-5}"
  done
}

kit_demarrer() {
  local worker
  worker=$(kit_compose ps -q worker 2>/dev/null) || return 1
  [ -z "$worker" ] || kit_compose stop worker || return 1
  kit_compose up -d db qdrant || return 1
  kit_compose up -d app || return 1
  kit_attendre_app_saine || { kit_erreur "l'application n'est pas devenue saine"; return 1; }
  kit_compose up -d worker || return 1
  kit_attendre_sante
}

kit__statut() { [ -z "${STATUS_FILE:-}" ] || printf '%s\n' "$1" > "$STATUS_FILE"; return 0; }

kit__restaurer() {
  local version=$1 sauvegarde=$2
  kit_compose stop app worker || { kit_erreur "impossible d'arrêter app/worker avant restauration"; return 2; }
  if [ -n "$sauvegarde" ] && [ -s "$sauvegarde/env.sauvegarde" ]; then
    cp "$sauvegarde/env.sauvegarde" "$KIT_ENV" || return 2
    chmod 600 "$KIT_ENV" || return 2
  fi
  kit_ecrire "$VERSION_VAR" "$version"
  kit_compose up -d db qdrant || return 2
  [ -z "$sauvegarde" ] || produit_restaurer "$sauvegarde" || {
    kit_erreur "restauration de $sauvegarde impossible"
    return 2
  }
  kit_demarrer || { kit_erreur "$PRODUIT $version ne redevient pas sain"; return 2; }
  kit_dire "$PRODUIT revenu en $version."
}

kit_mettre_a_jour() {
  local cible=${1:-} precedente sauvegarde code=0
  trap 'kit__statut $?' EXIT

  if [ -z "$cible" ] || ! kit_version_valide "$cible"; then
    kit_erreur "version attendue : X.Y.Z (ou edge pour une recette), reçu « $cible »"
    exit 1
  fi
  kit_verifier_variables || exit 1
  precedente=$(kit_lire "$VERSION_VAR")
  kit_version_valide "$precedente" || { kit_erreur "$VERSION_VAR invalide dans .env"; exit 1; }
  kit_dire "mise à jour $precedente → $cible"
  kit_verifier_disque || exit 1

  mkdir -p "$KIT_ETAT" "$KIT_DIR/sauvegardes"
  sauvegarde="$KIT_DIR/sauvegardes/$(date +%Y%m%d-%H%M%S)-$precedente"
  mkdir "$sauvegarde" || exit 1
  if ! produit_sauvegarder "$sauvegarde"; then
    rm -rf "$sauvegarde"
    kit_erreur "sauvegarde impossible : rien n'a été modifié"
    exit 1
  fi
  kit_dire "sauvegarde : $sauvegarde"

  if ! kit_verifier_signatures "$cible"; then
    rm -rf "$sauvegarde"
    exit 1
  fi
  if ! ( export "$VERSION_VAR=$cible"; kit_compose pull ); then
    rm -rf "$sauvegarde"
    kit_erreur "téléchargement des images $cible impossible : rien n'a été modifié"
    exit 1
  fi

  printf '%s\n' "$sauvegarde" > "$KIT_ETAT/derniere-sauvegarde"
  printf '%s\n' "$precedente" > "$KIT_ETAT/version-precedente"
  kit_ecrire "$VERSION_VAR" "$cible"
  if kit_demarrer; then
    kit_dire "$PRODUIT $cible en service."
    exit 0
  fi

  kit_erreur "$cible ne démarre pas sainement : retour à $precedente"
  kit_compose logs --tail 50 >&2 || true
  kit__restaurer "$precedente" "$sauvegarde" || code=$?
  [ "$code" -eq 0 ] && exit 1
  kit_erreur "RETOUR ARRIÈRE INCOMPLET — intervention requise (sauvegarde : $sauvegarde)"
  exit 2
}

kit_revenir() {
  local cible=${1:-} sauvegarde code=0
  trap 'kit__statut $?' EXIT
  [ -n "$cible" ] || cible=$(cat "$KIT_ETAT/version-precedente" 2>/dev/null || true)
  if [ -z "$cible" ] || ! kit_version_valide "$cible"; then
    kit_erreur "aucune version précédente connue : indiquer la version (rollback.sh X.Y.Z)"
    exit 1
  fi
  kit_verifier_variables || exit 1
  sauvegarde=$(cat "$KIT_ETAT/derniere-sauvegarde" 2>/dev/null || true)
  kit_dire "retour à $cible (sauvegarde : ${sauvegarde:-aucune})"
  kit__restaurer "$cible" "$sauvegarde" || code=$?
  [ "$code" -eq 0 ] && exit 0
  exit 2
}
