# Ouverture d'un site par commande console (module 14)

Commande : `php bin/console formation:sites:open <fichier.yaml> [--dry-run]`

Les commandes `php bin/console` de ce document se lancent dans le conteneur PHP (`make php-shell`), ou depuis l'hôte avec `make console ARGS='formation:sites:open data/formation/sites/site-be.yaml --dry-run'`.

Elle crée ou met à jour un **channel** Sylius à partir d'un fichier YAML, et le rattache à ses payment methods et shipping methods existantes (y compris le tarif `flat_rate` du channel). Elle est **idempotente** : la clé est le `code` du channel, une deuxième exécution ne change rien.

Versions : Sylius 2.3.1, PHP 8.3. Aucune migration, aucune interface (donc pas de Twig Hook) : c'est une commande console.

## Installation et configuration

Rien à installer : les classes sont dans `src/Formation/Module14MultiSite/` et enregistrées automatiquement (`config/services.yaml`, autowire sur `App\`). Vérifier :

```bash
php bin/console list formation
```

### Prérequis (jamais créés par la commande)

Doivent déjà exister, sinon la commande s'arrête avec **toutes** les erreurs d'un coup : locales, devises, zone de taxe par défaut, menu taxon, payment methods et shipping methods citées. Avec le jeu de données de formation (`sylius:fixtures:load formation_multisite`), ils existent.

### Fichier de définition

Exemple complet : `data/formation/sites/site-be.yaml`.

| Clé | Obligatoire | Rôle |
|---|---|---|
| `code` | oui | Code du channel (lettres, chiffres, `_`, `-`). Clé d'idempotence |
| `name` | oui | Nom affiché |
| `hostname` | oui | Nom d'hôte sans schéma. Doit être unique : un hostname déjà pris par un autre channel est refusé |
| `default_locale`, `locales` | oui | La locale par défaut doit faire partie de `locales` |
| `base_currency`, `currencies` | oui | La devise de base doit faire partie de `currencies` |
| `default_tax_zone` | non | Code de la zone de taxe |
| `tax_calculation_strategy` | non | `order_items_based` ou `order_item_units_based`. Absent : inchangé (un nouveau channel reçoit le défaut de Sylius) |
| `menu_taxon` | non | Code du taxon racine du menu |
| `contact_email` | non | Email de contact |
| `enabled` | non | `true` ou `false`. Absent : inchangé (un nouveau channel est actif par défaut) |
| `payment_methods` | non | Liste de codes à rattacher au channel |
| `shipping_methods` | non | Liste `{ code, amount }`. `amount` (centimes) est **obligatoire** pour une method `flat_rate`, sauf si un tarif existe déjà pour ce channel |

Règles : une clé inconnue est refusée (faute de frappe) ; un champ optionnel absent n'est **pas modifié** ; les locales et devises en trop sur un channel existant sont **conservées** avec un avertissement, jamais retirées ; une shipping method dont le calculateur n'est pas `flat_rate` n'est **pas rattachée** (avertissement), sauf si elle a déjà une configuration pour ce channel : sans tarif, elle serait vendable puis planterait au calcul de livraison (à rattacher dans l'admin après l'ouverture) ; le hostname est converti en minuscules.

## Utilisation

```bash
php bin/console formation:sites:open data/formation/sites/site-be.yaml --dry-run   # simulation : rien n'est écrit
php bin/console formation:sites:open data/formation/sites/site-be.yaml             # exécution
php bin/console formation:sites:list                                               # contrôle
```

La simulation affiche les changements (tableau « Action / Élément / Détail ») puis annule la transaction. L'exécution fait un seul `flush` dans une transaction et écrit une ligne dans le journal (`Ouverture de site`).

Pour le site exemple, la simulation annonce 14 changements ; la seconde exécution répond « déjà conforme : rien à changer ». Le site répond ensuite sur `http://be.sylius.localhost` (catalogue vide : produits et prix par channel sont hors périmètre).

### Test manuel conseillé

1. `php bin/console formation:sites:open data/formation/sites/site-be.yaml --dry-run` : 14 changements, aucun site créé (`formation:sites:list`).
2. Même commande sans `--dry-run`, puis une seconde fois : « déjà conforme ».
3. `http://be.sylius.localhost/` redirige vers `/fr_FR/` et affiche « Site Belgique ».
4. Pour revenir en arrière en local : `scripts/formation/db-dump.sh` **avant**, puis `scripts/formation/db-restore.sh <dump>`.

### Erreurs courantes

| Message | Cause |
|---|---|
| `Zone « XX » introuvable` (ou locale, devise, taxon, method) | Prérequis absent : le créer d'abord (admin ou fixtures) |
| `Le hostname « … » est déjà utilisé par le channel « … »` | Sylius n'impose pas l'unicité du hostname : la commande la contrôle |
| `le montant « amount » (centimes) est obligatoire` | Method `flat_rate` sans tarif pour ce channel : sans lui Sylius lève `MissingChannelConfigurationException` au calcul de livraison |

## Tests et qualité

```bash
vendor/bin/phpunit tests/Formation/Module14MultiSite   # 37 tests unitaires
make qa                                                # ECS, PHPStan niveau 9, PHPUnit (dans le conteneur)
```

| Fichier de test | Couvre |
|---|---|
| `SiteDefinitionLoaderTest` | lecture YAML, champs obligatoires, clés inconnues, cohérence locale / devise, hostname, montants |
| `SiteProvisionerTest` | création, idempotence (2e passage sans changement), mise à jour, champs optionnels absents non écrasés, tarif `flat_rate`, prérequis manquants, hostname dupliqué (et même channel), calculateur non géré, locales conservées, validation (groupe `sylius`, avant tout rattachement, sautée sans changement) |
| `OpenSiteCommandTest` | `--dry-run` (rollback, clear, jamais de flush), exécution (flush, commit, journal), erreurs (rollback, code retour 1, exception inattendue relancée), fichier invalide |

Il n'y a pas de test d'intégration avec base de données dans ce dépôt : le test manuel ci-dessus le remplace.

## Comment étendre cette fonctionnalité

- **Un nouveau champ du channel** (par exemple `theme_name`) : l'ajouter à `SiteDefinition`, à la liste `KEYS` et à la lecture dans `SiteDefinitionLoader`, puis une comparaison dans `SiteProvisioner::applyChannel()`, avec un test dans chaque classe de test.
- **Un autre calculateur de livraison** (`per_unit_rate`) : étendre `SiteProvisioner::applyShippingMethod()`. La forme de la configuration est celle du calculateur dans le cœur de Sylius, à lire avant d'écrire.
- **Création des prérequis** (zones, taxes, methods) : à garder dans un service séparé, pour que la commande reste prévisible.
- **Produits et prix du nouveau site** : hors périmètre. Voir `ChannelPricing` et l'assignation des produits aux channels.

## Changelog

Format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

### [Non publié]

#### Ajouté
- Commande `formation:sites:open` : création ou mise à jour idempotente d'un channel depuis un fichier YAML, avec `--dry-run`.
- Rattachement des payment methods et shipping methods, et tarif `flat_rate` par channel.
- Exemple `data/formation/sites/site-be.yaml` et 37 tests unitaires.

#### Corrigé
- `src/Entity/Shipping/ShippingMethodRule.php` : la classe avait été renommée `PShippingMethodRule` par erreur (modification non commitée), ce qui cassait le mapping.

## Sources

- Sylius 2.3, branche `2.3` : `ChannelExampleFactory`, `ChannelFactory`, contraintes de validation du channel, `FlatRateCalculator` (vérifiés sur GitHub et dans le `vendor` local 2.3.1).
- `CLAUDE.md` du projet (règle d'extension, interdits), `docs/formation/donnees-et-hotfix.md` (modèle de simulation et de transaction).
