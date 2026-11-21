#!/usr/bin/env bash
# Formation : test de scripts/formation/console.sh avec un faux « docker compose » (aucun Docker requis).
#   bash tests/Formation/Scripts/console-test.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
SCRIPT="$ROOT/scripts/formation/console.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Faux « docker compose » :
#   ps   : journalise ses arguments (FAKE_PS_LOG), affiche FAKE_PS_OUT et sort avec FAKE_PS_RC (0 par défaut)
#   exec : journalise un argument par ligne (FAKE_LOG) et sort avec FAKE_EXEC_RC (0 par défaut)
cat > "$WORK/fake-compose" <<'EOF'
#!/usr/bin/env bash
if [ "${1:-}" = "ps" ]; then
    printf '%s\n' "$*" > "$FAKE_PS_LOG"
    printf '%s' "${FAKE_PS_OUT:-}"
    exit "${FAKE_PS_RC:-0}"
fi
if [ "${1:-}" = "exec" ]; then
    printf '%s\n' "$@" > "$FAKE_LOG"
    exit "${FAKE_EXEC_RC:-0}"
fi
echo "commande inattendue : $*" >&2
exit 99
EOF
chmod +x "$WORK/fake-compose"

FAILURES=0
check() { # $1 = description, $2 = attendu, $3 = obtenu
    if [ "$2" = "$3" ]; then
        echo "ok   - $1"
    else
        echo "FAIL - $1"
        echo "       attendu : $2"
        echo "       obtenu  : $3"
        FAILURES=$((FAILURES + 1))
    fi
}

# Exécute le script (via bash : le bit exécutable n'est pas garanti selon le système) sans terminal, avec le faux compose
run() {
    DOCKER_COMPOSE="$WORK/fake-compose" FAKE_LOG="$WORK/exec.log" FAKE_PS_LOG="$WORK/ps.log" bash "$SCRIPT" "$@" < /dev/null > "$WORK/stdout" 2> "$WORK/stderr"
}

exec_called() { [ -f "$WORK/exec.log" ] && echo présent || echo absent; }

# 1. Conteneur en marche : la console est lancée dans le conteneur, sans terminal (-T), avec Xdebug coupé
rm -f "$WORK/exec.log"
FAKE_PS_OUT=$'mailhog\nmysql\nphp\n' run formation:sites:list
check "code de retour 0" "0" "$?"
check "arguments passés à docker compose exec" "exec -T -e XDEBUG_MODE=off php php bin/console formation:sites:list" "$(tr '\n' ' ' < "$WORK/exec.log" | sed 's/ $//')"
check "services demandés sous la forme portable (compose v1 et v2)" "ps --services --filter status=running" "$(cat "$WORK/ps.log")"

# 2. Les arguments sont conservés. Lignes du journal d'exec : 1 exec, 2 -T, 3 -e, 4 XDEBUG_MODE=off, 5 php, 6 php,
#    7 bin/console, puis les arguments du script à partir de la ligne 8.
rm -f "$WORK/exec.log"
FAKE_PS_OUT=$'php\n' run formation:sites:open "mon fichier.yaml" --dry-run
check "commande transmise" "formation:sites:open" "$(sed -n '8p' "$WORK/exec.log")"
check "argument avec espace conservé" "mon fichier.yaml" "$(sed -n '9p' "$WORK/exec.log")"
check "option transmise" "--dry-run" "$(sed -n '10p' "$WORK/exec.log")"

# 3. Conteneur arrêté : message clair, rien n'est exécuté
rm -f "$WORK/exec.log"
FAKE_PS_OUT=$'mysql\nnginx\n' run formation:sites:list
check "code de retour 1 si le conteneur php est arrêté" "1" "$?"
check "message d'aide" "Le conteneur php ne tourne pas. Démarrez l'environnement : make up" "$(cat "$WORK/stderr")"
check "aucun exec lancé (conteneur arrêté)" "absent" "$(exec_called)"

# 4. Un service dont le nom contient « php » n'est pas pris pour le conteneur php
rm -f "$WORK/exec.log"
FAKE_PS_OUT=$'phpmyadmin\nmysql\n' run formation:sites:list
check "service phpmyadmin ignoré" "1" "$?"
check "aucun exec lancé (phpmyadmin)" "absent" "$(exec_called)"

# 5. Docker indisponible (démon arrêté, compose absent) : message distinct de « conteneur arrêté »
rm -f "$WORK/exec.log"
FAKE_PS_OUT=$'Cannot connect to the Docker daemon' FAKE_PS_RC=1 run formation:sites:list
check "code de retour 1 si docker est indisponible" "1" "$?"
check "message docker indisponible" "docker compose est indisponible (Docker est-il installé et démarré ?) : Cannot connect to the Docker daemon" "$(cat "$WORK/stderr")"
check "aucun exec lancé (docker indisponible)" "absent" "$(exec_called)"

# 6. Le code de retour de la commande est propagé
rm -f "$WORK/exec.log"
FAKE_PS_OUT=$'php\n' FAKE_EXEC_RC=3 run formation:sites:list
check "code de retour de la console propagé" "3" "$?"

# 7. Un argument vide est transmis tel quel
rm -f "$WORK/exec.log"
FAKE_PS_OUT=$'php\n' run formation:sites:list ""
check "argument vide conservé" "9" "$(wc -l < "$WORK/exec.log" | tr -d ' ')"

if [ "$FAILURES" -gt 0 ]; then
    echo "$FAILURES test(s) en échec"
    exit 1
fi
echo "Tous les tests passent."
