#!/usr/bin/env bash
# Formation M10 : contrôle avant et après une montée de version (patch ou minor).
#   scripts/formation/upgrade-check.sh
# À lancer sur une branche dédiée : git switch -c upgrade/sylius-2.3.x
set -euo pipefail

echo "== Paquets Sylius pouvant être mis à jour"
composer outdated "sylius/*" --direct || true

echo "== Failles connues dans les dépendances"
composer audit

echo "== Conflits connus du dépôt"
cat CONFLICTS.md || true

echo "== Schéma de base : migrations en attente et cohérence du mapping"
bin/console doctrine:migrations:status
bin/console doctrine:schema:validate --skip-sync

echo "== Dépréciations (Rector en mode simulation)"
vendor/bin/rector process --dry-run || true

echo "== Tests"
vendor/bin/phpunit tests/Formation

echo "Lisez UPGRADE-<version>.md de la version cible avant 'composer update \"sylius/*\" --with-dependencies'."
