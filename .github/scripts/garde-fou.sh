#!/usr/bin/env bash
# Garde-fou du dépôt public, lancé par la CI à chaque push et pull request.
#
#   .github/scripts/garde-fou.sh [dossier]   (dossier courant par défaut)
#
# Refuse :
# - une adresse IPv4 publique. Sont autorisées les plages de documentation,
#   privées, locales, réservées (lien local, CGNAT, banc d'essai, 224.0.0.0/3),
#   les adresses de réseau en .0 et example.com. Celles dont le premier octet
#   est inférieur à 20 sont ignorées : elles se confondent avec les numéros de
#   version (1.2.0.139) ;
# - les fichiers de secrets : acme.json, *.keystore, *.jks, .env, .env.*
#   (sauf *.example), clés SSH id_*.
#
# Les exemples du code, des tests et de la documentation utilisent
# example.test et 192.0.2.0/24 (voir CONTRIBUTING.md).
#
# Code de sortie : 0 rien trouvé, 1 au moins une trouvaille.
set -euo pipefail

dossier=${1:-.}
cd "$dossier"

autorisee() {
  local a b c d
  IFS=. read -r a b c d <<< "$1"
  for o in "$a" "$b" "$c" "$d"; do [ "$o" -le 255 ] 2>/dev/null || return 0; done
  [ "$a" -lt 20 ] && return 0
  # Adresse de réseau (x.y.z.0) : pas un hôte, et forme des numéros de version
  # de navigateur (Chrome/141.0.0.0).
  [ "$d" -eq 0 ] && return 0
  # Multicast et plages réservées (224.0.0.0/3), utilisées par les tests SSRF.
  [ "$a" -ge 224 ] && return 0
  case "$1" in 93.184.216.34) return 0 ;; esac   # example.com (IANA)
  case "$a.$b.$c" in 192.0.2|198.51.100|203.0.113) return 0 ;; esac
  case "$a.$b" in 192.168|169.254|198.18|198.19) return 0 ;; esac
  [ "$a" -eq 127 ] && return 0
  [ "$a" -eq 172 ] && [ "$b" -ge 16 ] && [ "$b" -le 31 ] && return 0
  [ "$a" -eq 100 ] && [ "$b" -ge 64 ] && [ "$b" -le 127 ] && return 0   # CGNAT
  return 1
}

trouvailles=()

if adresses=$(grep -rnoIE --exclude-dir=.git --exclude-dir=node_modules --exclude-dir=vendor \
                -e '(^|[^0-9.])([0-9]{1,3}\.){3}[0-9]{1,3}([^0-9.]|$)' . \
              | sed -E 's/:[^0-9]*(([0-9]{1,3}\.){3}[0-9]{1,3})[^0-9]*$/:\1/'); then
  :
else
  statut=$?
  if [ "$statut" -ne 1 ]; then
    echo "garde-fou : échec de l'analyse des adresses IPv4 (code $statut)." >&2
    exit "$statut"
  fi
fi

while IFS= read -r ligne; do
  ip=${ligne##*:}
  autorisee "$ip" || trouvailles+=("${ligne#./}")
done <<< "$adresses"

while IFS= read -r f; do
  trouvailles+=("${f#./}:0:fichier de secrets")
done < <(find . \( -path ./.git -o -path '*/node_modules' -o -path ./vendor \) -prune -o -type f \
           \( -name acme.json -o -name '*.keystore' -o -name '*.jks' -o -name .env \
              -o \( -name '.env.*' ! -name '*.example' \) -o \( -name 'id_*' ! -name '*.pub' \) \) -print)

if [ ${#trouvailles[@]} -gt 0 ]; then
  printf '%s\n' "${trouvailles[@]}"
  echo "garde-fou : ${#trouvailles[@]} trouvaille(s). Utilisez des données fictives (example.test, 192.0.2.0/24)." >&2
  exit 1
fi
echo "garde-fou : rien à signaler." >&2
