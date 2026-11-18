#!/usr/bin/env bash
# Formation M9 / M10 : restaure un instantané dans la base locale, puis applique les migrations plus récentes.
#
#   scripts/formation/db-restore.sh dumps/sylius-2026-11-10-Version20261109215353.sql.gz [nom_de_base]
#
# La base cible est SUPPRIMÉE puis recréée. Par défaut : sylius_dev. Pour essayer sans risque :
# restaurer dans une autre base (sylius_copy) et pointer DATABASE_URL dessus.
set -euo pipefail

cd "$(dirname "$0")/../.."
FILE="${1:?Usage : db-restore.sh <fichier.sql.gz> [nom_de_base]}"
DB="${2:-${DB_NAME:-sylius_dev}}"
[ -f "$FILE" ] || { echo "Fichier introuvable : $FILE" >&2; exit 1; }

echo "Restauration de ${FILE} dans la base ${DB} (la base existante sera supprimée)"
docker compose exec -T mysql mysql -uroot -e "DROP DATABASE IF EXISTS \`${DB}\`; CREATE DATABASE \`${DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>&1 | grep -v Warning || true
gunzip -c "$FILE" | docker compose exec -T mysql mysql -uroot "${DB}" 2>&1 | grep -v Warning || true

if [ "$DB" = "${DB_NAME:-sylius_dev}" ]; then
    echo "Migrations plus récentes que l'instantané :"
    docker compose exec -T -e XDEBUG_MODE=off php php bin/console doctrine:migrations:migrate -n
fi
echo "Restauration terminée."
