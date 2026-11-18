#!/usr/bin/env bash
# Formation M8 : parcours d'achat via l'API Shop (catalogue, panier, article) et token admin.
#
#   SITE=http://fr.sylius.localhost LOCALE=fr_FR scripts/formation/api-parcours.sh
#
# Variables : SITE (défaut http://localhost), ADMIN_EMAIL et ADMIN_PASSWORD (facultatifs : token admin),
#             LOCALE (défaut en_US). Les identifiants de test sont définis dans
#             config/packages/formation_m14_multisite_fixtures.yaml.
set -euo pipefail

SITE="${SITE:-http://localhost}"
LOCALE="${LOCALE:-en_US}"
BASE="${SITE}/api/v2"
LD='Content-Type: application/ld+json'

echo "== 1. Token admin (facultatif)"
if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
    curl -s -X POST "${BASE}/admin/administrators/token" -H 'Content-Type: application/json' \
        -d "{\"email\":\"${ADMIN_EMAIL}\",\"password\":\"${ADMIN_PASSWORD}\"}" | head -c 200; echo
else
    echo "ignoré (ADMIN_EMAIL / ADMIN_PASSWORD non définis)"
fi

echo "== 2. Catalogue du site ${SITE}"
curl -s "${BASE}/shop/products?itemsPerPage=3" -H 'Accept: application/ld+json' | head -c 400; echo

echo "== 3. Création du panier"
CART=$(curl -s -X POST "${BASE}/shop/orders" -H "${LD}" -d "{\"localeCode\":\"${LOCALE}\"}")
TOKEN=$(printf '%s' "$CART" | sed -n 's/.*"tokenValue":"\([^"]*\)".*/\1/p')
echo "panier : ${TOKEN:-introuvable}"
[ -n "${TOKEN}" ] || { echo "$CART" | head -c 400; exit 1; }

echo "== 4. Ajout d'un article (première variante du premier produit du site)"
VARIANT=$(curl -s "${BASE}/shop/products?itemsPerPage=1" -H 'Accept: application/ld+json' \
    | grep -o '"variants":\["[^"]*"' | head -1 | sed 's/.*\["//; s/"$//; s#\\/#/#g')
echo "variante : ${VARIANT:-introuvable}"
[ -n "${VARIANT}" ] || { echo "aucune variante trouvée sur ce site"; exit 1; }
curl -s -X POST "${BASE}/shop/orders/${TOKEN}/items" -H "${LD}" \
    -d "{\"productVariant\":\"${VARIANT}\",\"quantity\":1}" | head -c 400; echo

echo "== 5. Lecture du panier"
curl -s "${BASE}/shop/orders/${TOKEN}" -H 'Accept: application/ld+json' | head -c 500; echo
