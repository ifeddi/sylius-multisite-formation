#!/usr/bin/env bash
# Formation M9 / M10 : exporte la base locale (instantané partageable entre développeurs).
#
#   scripts/formation/db-dump.sh                  # -> dumps/sylius-<date>-<migration>.sql.gz
#   scripts/formation/db-dump.sh mon-fichier.sql.gz
#
# Le nom du fichier contient la dernière migration appliquée : on sait ainsi pour quelle version du schéma
# l'instantané a été fait. Le dossier dumps/ n'est pas versionné (volumineux, données personnelles
# possibles) : partager le fichier par un stockage d'équipe, jamais par Git.
set -euo pipefail

cd "$(dirname "$0")/../.."
DB="${DB_NAME:-sylius_dev}"
mkdir -p dumps

LAST="$(docker compose exec -T mysql mysql -uroot -N -e "SELECT version FROM \`${DB}\`.sylius_migrations ORDER BY executed_at DESC, version DESC LIMIT 1" 2>/dev/null | tr -d '\r' | sed 's/.*\\//')"
OUT="${1:-dumps/sylius-$(date +%F-%H%M)-${LAST:-sans-migration}.sql.gz}"

# --single-transaction : instantané cohérent sans verrouiller les tables ; --no-tablespaces : pas de droit PROCESS requis
docker compose exec -T mysql mysqldump -uroot --single-transaction --no-tablespaces --routines "${DB}" 2>/dev/null | gzip > "${OUT}"

echo "Instantané écrit : ${OUT} ($(du -h "${OUT}" | cut -f1)), schéma : ${LAST:-inconnu}"
