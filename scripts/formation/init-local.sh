#!/usr/bin/env bash
# Formation M1 / M14 : (ré)initialise l'instance locale avec le jeu de données multi-sites.
#
#   scripts/formation/init-local.sh                 # démarre, installe ce qui manque, charge le jeu de données
#   scripts/formation/init-local.sh --reset         # supprime et recrée la base, puis recharge le jeu de données
#   scripts/formation/init-local.sh --no-fixtures   # prépare l'instance sans charger de données (base vide)
#
# Après « make clean » (qui supprime aussi les volumes vendor et base), relancer simplement ce script :
# il réinstalle les dépendances (plusieurs minutes), recrée la base et recharge le jeu de données.
# Réservé au développement local : le script refuse de tourner si APP_ENV n'est pas « dev ».
set -euo pipefail

# Git Bash (Windows) transforme les chemins Unix passés en argument (/srv/... devient C:/Program Files/Git/srv/...).
export MSYS_NO_PATHCONV=1

cd "$(dirname "$0")/../.."

RESET=0
FIXTURES=1
for arg in "$@"; do
    case "$arg" in
        --reset) RESET=1 ;;
        --no-fixtures) FIXTURES=0 ;;
        -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
        *) echo "Option inconnue : $arg" >&2; exit 2 ;;
    esac
done

DC="docker compose"
PHP="$DC exec -T -e XDEBUG_MODE=off php"
CONSOLE="$PHP php bin/console"
DB="${DB_NAME:-sylius_dev}"
step() { printf '\n==> %s\n' "$*"; }

if [ ! -f compose.override.yml ]; then
    step "compose.override.yml absent : copie du modèle (compose.override.dist.yml)"
    cp compose.override.dist.yml compose.override.yml
    echo "Attention : vendor/ et var/ ne sont alors pas dans des volumes Docker (démarrage très lent sous Windows)."
fi

step "1/8 Conteneurs"
$DC up -d
$DC exec -T -u root php chown -R 1000:1000 /srv/sylius/var /srv/sylius/vendor

APP_ENV_VALUE="$($DC exec -T php printenv APP_ENV | tr -d '\r')"
if [ "$APP_ENV_VALUE" != "dev" ]; then
    echo "Refus : APP_ENV=$APP_ENV_VALUE (ce script ne s'exécute qu'en « dev »)." >&2
    exit 1
fi

step "2/8 Dépendances PHP"
if $PHP test -f vendor/autoload.php; then
    echo "déjà installées (volume vendor)"
else
    echo "installation (plusieurs minutes)..."
    $DC exec -T -e COMPOSER_PROCESS_TIMEOUT=3600 -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction --no-scripts --prefer-dist
fi

step "3/8 Base de données ${DB}"
if [ "$RESET" -eq 1 ]; then
    $DC exec -T mysql mysql -uroot -e "DROP DATABASE IF EXISTS \`${DB}\`" 2>&1 | grep -v Warning || true
fi
$DC exec -T mysql mysql -uroot -e "CREATE DATABASE IF NOT EXISTS \`${DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>&1 | grep -v Warning || true

step "4/8 Migrations"
$CONSOLE doctrine:migrations:migrate -n

step "5/8 Clés JWT (créées seulement si absentes)"
$CONSOLE lexik:jwt:generate-keypair --skip-if-exists

step "6/8 Assets de la boutique et de l'administration"
if [ -d public/build/shop ] && [ -d public/build/admin ]; then
    echo "déjà compilés (public/build)"
else
    $DC run --rm nodejs
fi

if [ "$FIXTURES" -eq 1 ]; then
    step "7/8 Jeu de données multi-sites (3 à 4 minutes)"
    $CONSOLE sylius:fixtures:load formation_multisite -n
    echo "Application de la promotion de catalogue (Messenger)..."
    $CONSOLE messenger:consume main catalog_promotion_removal --time-limit=40 --limit=100 || true
else
    step "7/8 Jeu de données : ignoré (--no-fixtures)"
fi

step "8/8 Serveur web"
$DC restart nginx >/dev/null
for _ in $(seq 1 30); do
    code="$(curl -s -o /dev/null -m 60 -w '%{http_code}' http://localhost/ || true)"
    case "$code" in 200|302) break ;; esac
    sleep 2
done
echo "http://localhost -> HTTP ${code:-?}"

step "Sites disponibles"
$CONSOLE formation:sites:list
echo
echo "Liens et comptes de test : docs/formation/README.md"
