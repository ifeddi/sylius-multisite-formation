#!/usr/bin/env bash
# Formation M9 : runbook de déploiement (build, sauvegarde, migrations, assets, cache).
# À répéter sur un clone de production avant le vrai déploiement. Jamais de fixtures en production.
#
# Variables attendues : DB_NAME, DB_HOST, DB_USER (le mot de passe vient de l'environnement : MYSQL_PWD)
set -euo pipefail

export APP_ENV=prod APP_DEBUG=0
BACKUP="backup-$(date +%F-%H%M).sql"

echo "1/8 Sauvegarde de la base -> ${BACKUP}"
mysqldump -h "${DB_HOST:?}" -u "${DB_USER:?}" "${DB_NAME:?}" > "${BACKUP}"

echo "2/8 Dépendances PHP (sans dev)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "3/8 Assets"
yarn install --frozen-lockfile
yarn build:prod

echo "4/8 Migrations : dry-run puis exécution"
bin/console doctrine:migrations:migrate --no-interaction --dry-run
bin/console doctrine:migrations:migrate --no-interaction

echo "5/8 Clés JWT (créées seulement si absentes)"
bin/console lexik:jwt:generate-keypair --skip-if-exists

echo "6/8 Assets publics et cache"
bin/console assets:install public
bin/console cache:clear
bin/console cache:warmup

echo "7/8 Redémarrage des workers Messenger (Supervisor relance le nouveau code)"
bin/console messenger:stop-workers

echo "8/8 Vérification des sites"
bin/console formation:sites:list

echo "Déploiement terminé. Retour arrière : restaurer ${BACKUP} et redéployer la release précédente."
