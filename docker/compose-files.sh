# Résolution de la commande `docker compose` à utiliser — À SOURCER, pas à
# exécuter. Définit la variable $COMPOSE.
#
#   . ./docker/compose-files.sh
#   $COMPOSE up -d
#
# Deux cas, et c'est ce qui permet de faire évoluer la structure des fichiers
# compose sans imposer une intervention manuelle sur les serveurs existants :
#
#   1. Le .env porte COMPOSE_FILE (installations faites par install.sh).
#      docker compose lit lui-même cette variable : aucune option -f n'est
#      nécessaire, et la commande est la même quel que soit le mode.
#
#   2. Pas de COMPOSE_FILE (installations antérieures à l'installeur).
#      On retombe sur docker-compose.prod.yml, exactement comme avant.
#
# Migrer une installation historique = ajouter UNE ligne à son .env, après
# avoir vérifié le rendu avec `docker compose config`.

if grep -qE '^[[:space:]]*COMPOSE_FILE=' .env 2>/dev/null; then
    COMPOSE="docker compose"
elif [ -f docker-compose.prod.yml ]; then
    COMPOSE="docker compose -f docker-compose.prod.yml"
else
    COMPOSE="docker compose"
fi
