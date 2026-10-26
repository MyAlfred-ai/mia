#!/usr/bin/env bash
# Installe les hooks git versionnés (scripts/hooks/) en pointant core.hooksPath dessus.
# À lancer une fois par clone du repo : ./scripts/install-hooks.sh

set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

git config core.hooksPath scripts/hooks
chmod +x scripts/hooks/*

echo "Hooks git installés (core.hooksPath = scripts/hooks)."
git config --get core.hooksPath
