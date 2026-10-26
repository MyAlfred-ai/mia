#!/usr/bin/env bash
set -euo pipefail
KIT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/kit.sh
. "$KIT_DIR/lib/kit.sh"
kit_mettre_a_jour "$@"
