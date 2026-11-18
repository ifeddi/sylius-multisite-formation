#!/usr/bin/env bash
# Formation M11 / M13 : boucle courte de qualité avant chaque push (même contrôles que la CI).
#   scripts/formation/qa.sh            # depuis le conteneur PHP (make php-shell)
set -euo pipefail

vendor/bin/ecs check src tests
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit tests/Formation
