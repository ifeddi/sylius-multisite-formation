#!/usr/bin/env bash
# Formation M15 : inventaire des secrets suivis par Git (rien de réel ne doit y figurer).
#   scripts/formation/secrets-audit.sh
set -uo pipefail

status=0

echo "== Fichiers de clés ou d'environnement local suivis par Git"
tracked=$(git ls-files | grep -E '\.pem$|\.key$|\.env\.local|\.env\..*\.local' | grep -vE '(-test\.pem|/test\.key)$' || true)
if [ -n "$tracked" ]; then
    echo "$tracked"
    echo "-> ATTENTION : ces fichiers ne devraient pas être versionnés."
    status=1
else
    echo "aucun (hors clés de test *-test.pem et test.key)"
fi

echo
echo "== Historique : clés privées déjà commitées dans config/ ?"
history=$(git log --all --oneline -S'BEGIN PRIVATE KEY' -- config/ | head -5 || true)
if [ -n "$history" ]; then
    echo "$history"
    echo "-> Une clé privée a été commitée : la révoquer et la renouveler, ne pas se contenter de l'effacer."
    status=1
else
    echo "aucune trace"
fi

echo
echo "== Variables sensibles à fournir par environnement (jamais dans Git)"
printf '  %s\n' APP_SECRET DATABASE_URL MAILER_DSN JWT_PASSPHRASE JWT_SECRET_KEY JWT_PUBLIC_KEY SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH

echo
echo "== Dépendances : failles connues"
composer audit || status=1

exit $status
