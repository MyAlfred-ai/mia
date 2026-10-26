#!/usr/bin/env bash
set -euo pipefail
KIT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/kit.sh
. "$KIT_DIR/lib/kit.sh"
kit_verifier_variables
dossier="$KIT_DIR/sauvegardes/$(date +%Y%m%d-%H%M%S)-$(kit_lire "$VERSION_VAR")"
mkdir -p "$dossier"
if ! produit_sauvegarder "$dossier"; then
  rm -rf "$dossier"
  kit_erreur "sauvegarde impossible"
  exit 1
fi
kit_dire "sauvegarde : $dossier"
