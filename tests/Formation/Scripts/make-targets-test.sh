#!/usr/bin/env bash
# Formation : test des cibles « make qa » et « make test » avec un faux « docker compose » (aucun Docker requis).
#   bash tests/Formation/Scripts/make-targets-test.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"

if ! command -v make > /dev/null 2>&1; then
    if [ -n "${CI:-}" ]; then
        echo "make est absent de l'environnement de CI : le test ne peut pas s'exécuter." >&2
        exit 1
    fi
    echo "make est absent : test ignoré (installer make pour le lancer)."
    exit 0
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Faux « docker compose » : journalise chaque appel sur une ligne (FAKE_LOG) et échoue
# (code 1) quand les arguments contiennent FAKE_FAIL_ON.
cat > "$WORK/fake-compose" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_LOG"
if [ -n "${FAKE_FAIL_ON:-}" ] && [[ "$*" == *"$FAKE_FAIL_ON"* ]]; then
    exit 1
fi
exit 0
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
        if [ -s "$WORK/stderr" ]; then sed 's/^/       stderr  : /' "$WORK/stderr"; fi
        FAILURES=$((FAILURES + 1))
    fi
}

EXEC="exec -T -e XDEBUG_MODE=off php"
ECS="$EXEC vendor/bin/ecs check src tests"
STAN="$EXEC vendor/bin/phpstan analyse --no-progress"
UNIT="$EXEC vendor/bin/phpunit tests/Formation"

# 1. Affichage des commandes (make -n), avec le faux compose pour ne dépendre d'aucune installation
dry() { make -n --no-print-directory -C "$ROOT" DOCKER_COMPOSE=fake-compose "$@" 2> "$WORK/stderr" | tr -d '\r' | tr '\n' '|'; }
check "make -n qa : ECS, PHPStan puis PHPUnit, dans cet ordre" "fake-compose $ECS|fake-compose $STAN|fake-compose $UNIT|" "$(dry qa)"
check "make -n test : PHPUnit seul" "fake-compose $UNIT|" "$(dry test)"

# 2. Exécution : les trois commandes sont lancées, ECS en lecture seule
run() { rm -f "$WORK/calls.log"; DOCKER_COMPOSE="$WORK/fake-compose" FAKE_LOG="$WORK/calls.log" make -s --no-print-directory -C "$ROOT" "$@" > "$WORK/stdout" 2> "$WORK/stderr"; }
calls() { [ -f "$WORK/calls.log" ] && tr '\n' '|' < "$WORK/calls.log" || echo "aucun appel"; }

run qa
check "make qa : code de retour 0" "0" "$?"
check "make qa : commandes lancées dans l'ordre" "$ECS|$STAN|$UNIT|" "$(calls)"

run test
check "make test : code de retour 0" "0" "$?"
check "make test : PHPUnit seul" "$UNIT|" "$(calls)"

# 3. Une erreur ECS arrête tout : code non nul, ni PHPStan ni PHPUnit
FAKE_FAIL_ON="vendor/bin/ecs" run qa; RC=$?
check "make qa échoue si ECS échoue" "non nul" "$([ "$RC" -ne 0 ] && echo 'non nul' || echo 'nul')"
check "make qa s'arrête après ECS" "$ECS|" "$(calls)"

# 4. Une erreur PHPStan arrête PHPUnit
FAKE_FAIL_ON="vendor/bin/phpstan" run qa; RC=$?
check "make qa échoue si PHPStan échoue" "non nul" "$([ "$RC" -ne 0 ] && echo 'non nul' || echo 'nul')"
check "make qa s'arrête après PHPStan" "$ECS|$STAN|" "$(calls)"

# 5. Les cibles sont déclarées .PHONY : un fichier « qa » ou « test » ne doit pas les bloquer
mkdir "$WORK/phony"
cp "$ROOT/Makefile" "$WORK/phony/Makefile"
touch "$WORK/phony/qa" "$WORK/phony/test"
phony() { make -n --no-print-directory -C "$WORK/phony" DOCKER_COMPOSE=fake-compose "$@" 2> "$WORK/stderr" | tr -d '\r' | tr '\n' '|'; }
check "make -n qa malgré un fichier « qa »" "fake-compose $ECS|fake-compose $STAN|fake-compose $UNIT|" "$(phony qa)"
check "make -n test malgré un fichier « test »" "fake-compose $UNIT|" "$(phony test)"

# 6. Les commandes du Makefile sont celles de scripts/formation/qa.sh (pas de dérive entre les deux)
QA_SH="$(grep -E '^vendor/bin/' "$ROOT/scripts/formation/qa.sh" | tr -d '\r' | tr '\n' '|')"
MAKE_QA="$(dry qa | sed "s#fake-compose $EXEC ##g")"
check "make qa = commandes de scripts/formation/qa.sh" "$QA_SH" "$MAKE_QA"

if [ "$FAILURES" -gt 0 ]; then
    echo "$FAILURES test(s) en échec"
    exit 1
fi
echo "Tous les tests passent."
