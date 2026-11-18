#!/usr/bin/env bash
# Formation M9 / M15 : anonymise une COPIE de la base (données personnelles et secrets).
#
#   scripts/formation/db-anonymize.sh sylius_copy
#
# Étapes recommandées pour utiliser des données réelles en développement :
#   1. restaurer l'instantané dans une base séparée : scripts/formation/db-restore.sh <dump> sylius_copy
#   2. anonymiser cette copie                         : scripts/formation/db-anonymize.sh sylius_copy
#   3. exporter la copie anonymisée et la partager    : DB_NAME=sylius_copy scripts/formation/db-dump.sh
# Jamais l'inverse : ne pas partager le dump brut puis « anonymiser chez soi ».
#
# Le script refuse la base de développement par défaut (sylius_dev) sans --force, pour éviter une erreur de cible.
set -euo pipefail

cd "$(dirname "$0")/../.."
DB="${1:?Usage : db-anonymize.sh <nom_de_base> [--force]}"
FORCE="${2:-}"
if [ "$DB" = "sylius_dev" ] && [ "$FORCE" != "--force" ]; then
    echo "Refus : sylius_dev est la base de développement. Anonymiser une copie, ou ajouter --force." >&2
    exit 1
fi

DEV_PASSWORD="${DEV_PASSWORD:-sylius}"
HASH="$(docker compose exec -T -e XDEBUG_MODE=off php php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$DEV_PASSWORD" | tr -d '\r' | tail -1)"
[ -n "$HASH" ] || { echo "Impossible de générer le hachage du mot de passe de test." >&2; exit 1; }

echo "Anonymisation de la base ${DB}..."
{ printf "SET @dev_password_hash = '%s';\n" "$HASH"; cat scripts/formation/anonymize.sql; } \
    | docker compose exec -T mysql mysql -uroot "${DB}" 2>&1 | grep -v Warning || true

echo
echo "Colonnes à relire à la main (jetons, secrets, configurations ajoutés par des plugins) :"
docker compose exec -T mysql mysql -uroot -N -e "SELECT CONCAT(table_name, '.', column_name) FROM information_schema.columns WHERE table_schema='${DB}' AND (column_name LIKE '%token%' OR column_name LIKE '%secret%' OR column_name LIKE '%api_key%' OR column_name LIKE '%password%' OR column_name = 'config') ORDER BY table_name" 2>/dev/null | tr -d '\r' | sed 's/^/  - /'
echo
echo "Terminé. Les comptes de test utilisent le mot de passe défini par DEV_PASSWORD (par défaut : voir README)."
