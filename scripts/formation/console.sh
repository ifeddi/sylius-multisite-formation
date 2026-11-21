#!/usr/bin/env bash
# Formation : lance la console Symfony dans le conteneur PHP (PHP 8.3), quel que soit le PHP installé sur le poste.
#
#   scripts/formation/console.sh formation:sites:list
#   make console ARGS="formation:sites:list"
#
# Pourquoi : le projet exige PHP >= 8.3. Un « php bin/console » lancé sur le poste avec un PHP plus ancien
# (XAMPP 8.2 par exemple) échoue avec « Composer detected issues in your platform ». Le conteneur a le bon PHP,
# les bonnes extensions et l'accès à MySQL. XDEBUG_MODE=off évite les lignes de journal Xdebug et accélère la commande.
#
# Variables facultatives :
#   DOCKER_COMPOSE      commande Compose (par défaut « docker compose »)
#   CONSOLE_FORCE_TTY=1 garde le terminal interactif sous Git Bash (par défaut coupé : docker.exe n'y voit pas de vrai
#                       terminal et répond « the input device is not a TTY ») ; utile avec winpty
# Sans terminal, les questions interactives ne s'affichent pas : ajouter --no-interaction (-n) aux commandes concernées.
set -euo pipefail

# Git Bash (Windows) convertirait les chemins Linux passés à Docker
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/../.."

DC="${DOCKER_COMPOSE:-docker compose}"

# Docker absent ou arrêté : on le dit, au lieu de laisser croire que seul le conteneur php est arrêté
if ! SERVICES="$($DC ps --services --filter status=running 2>&1)"; then
    echo "docker compose est indisponible (Docker est-il installé et démarré ?) : $SERVICES" >&2
    exit 1
fi

# « grep -q » quitterait dès la première correspondance et, avec pipefail, pourrait faire échouer le tube : sortie vers /dev/null
if ! printf '%s\n' "$SERVICES" | grep -x php > /dev/null; then
    echo "Le conteneur php ne tourne pas. Démarrez l'environnement : make up" >&2
    exit 1
fi

# Terminal interactif seulement si l'entrée et la sortie en sont un (sinon : -T, par exemple en CI ou en pipe)
T_OPT=""
if ! { [ -t 0 ] && [ -t 1 ]; }; then
    T_OPT="-T"
fi
case "$(uname -s)" in
    MINGW* | MSYS* | CYGWIN*)
        if [ -z "${CONSOLE_FORCE_TTY:-}" ]; then
            T_OPT="-T"
        fi
        ;;
esac

exec $DC exec $T_OPT -e XDEBUG_MODE=off php php bin/console "$@"
