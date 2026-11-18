# Données : initialiser, partager, corriger (hotfix)

Bonnes pratiques pour le jeu de données de l'instance multi-sites, le partage entre développeurs et les
correctifs de données en production. Les scripts cités sont dans `scripts/formation/`.

## 1. Quatre sortes de données, quatre façons de les gérer

| Donnée | Exemples | Où elle vit | Comment elle arrive |
|---|---|---|---|
| **Schéma** | tables, colonnes, index | migrations Doctrine (`migrations/`) | `doctrine:migrations:migrate` à chaque déploiement |
| **Référentiel de configuration** | channels, devises, pays, zones, taxes, méthodes de livraison | base de données | création initiale par migration ou commande d'import idempotente, puis administration ; **jamais par fixtures en production** |
| **Données de démonstration** | produits, clients, commandes de test | fixtures (`config/packages/formation_m14_multisite_fixtures.yaml`) | `sylius:fixtures:load`, **développement uniquement** |
| **Données de production** | vrais clients, vraies commandes, clés de paiement | base de production | créées par l'activité ; jamais copiées en développement sans anonymisation |

Règle d'or : ce qui doit exister partout (schéma, référentiel) est **versionné dans Git** ; ce qui est propre
à un environnement (démo, production) n'y est **jamais** copié tel quel.

## 2. Initialiser un poste (clean puis init)

`make init` installe la suite de démonstration par défaut de Sylius : ce n'est **pas** le jeu de données de
la formation. Utiliser le script du projet :

```bash
scripts/formation/init-local.sh                # démarre et charge le jeu de données multi-sites
scripts/formation/init-local.sh --reset        # supprime la base, la recrée, recharge le jeu de données
scripts/formation/init-local.sh --no-fixtures  # base migrée mais vide
```

Après `make clean` (qui supprime aussi les volumes `vendor` et `var`), relancer le même script : il réinstalle
les dépendances (plusieurs minutes), recrée la base et recharge le jeu de données. Il refuse de tourner si
`APP_ENV` n'est pas `dev`.

## 3. Partager des données entre développeurs

1. **Par défaut : les fixtures.** Tout le monde charge le même jeu de données depuis le code. Avantage :
   versionné, relu en pull request, reproductible. Limite : certaines valeurs sont aléatoires (les prix
   générés par Sylius changent à chaque chargement) ; pour un jeu strictement identique, fixer les valeurs
   dans la suite ou partager un instantané (point 2).
2. **Pour partager un état précis** (bug à reproduire, démonstration) : un instantané de base.
   ```bash
   scripts/formation/db-dump.sh                                   # -> dumps/sylius-<date>-<migration>.sql.gz
   scripts/formation/db-restore.sh dumps/<fichier>.sql.gz          # restaure puis applique les migrations plus récentes
   ```
   Le nom du fichier contient la dernière migration appliquée : on sait pour quel schéma il a été fait. Le
   dossier `dumps/` est ignoré par Git : partager le fichier par le stockage d'équipe, jamais par Git.
3. **Ne jamais partager un dump de production brut** (point 4).

## 4. Utiliser des données réelles en développement : anonymiser d'abord

Un dump de production contient des données personnelles (RGPD) et des secrets (clés des passerelles de
paiement, jetons). Procédure :

```bash
scripts/formation/db-restore.sh dumps/prod.sql.gz sylius_copy     # 1. dans une base séparée
scripts/formation/db-anonymize.sh sylius_copy                      # 2. anonymiser la copie
DB_NAME=sylius_copy scripts/formation/db-dump.sh                   # 3. exporter la copie anonymisée
```

`scripts/formation/anonymize.sql` remplace les identités, adresses, téléphones, adresses IP, notes, jetons
et configurations de passerelles, remplace les mots de passe par un mot de passe de test, et **conserve** les
commandes, produits et montants (le volume et la forme des données restent réalistes). Le script affiche à
la fin les colonnes qui ressemblent à des jetons ou secrets : **les relire**, surtout après l'ajout d'un
plugin. Testé sur ce projet : 0 client non anonymisé, 0 jeton, commandes conservées.

## 5. Corriger des données en production (hotfix de données)

### Principes

- **Jamais de SQL tapé à la main en production.** Un correctif de données est du code : écrit, relu,
  testé, versionné et déployé par la même chaîne que le reste.
- **Toujours simuler d'abord** (`--dry-run`), puis exécuter, puis vérifier.
- **Toujours pouvoir revenir en arrière** : sauvegarde avant, et instruction inverse ou restauration.
- **Toujours tracer** : qui, quoi, combien de lignes, quand, quel ticket.

### Migration de données ou commande ?

| Critère | Migration Doctrine | Commande console |
|---|---|---|
| Correctif **unique**, lié à une version | oui | possible |
| Doit s'appliquer **une fois par environnement**, dans l'ordre | oui (suivi automatique) | non (à tracer à la main) |
| Volume important (milliers de lignes ou plus) | non : bloque le déploiement | oui : par lots, relançable |
| Besoin de simulation, de journal détaillé | difficile | oui |
| Logique métier (calculs, appels à des services) | à éviter | oui |

Règle pratique : petit et unique, une migration ; gros, risqué ou relançable, une commande lancée dans le
runbook de déploiement. Modèle de commande dans ce dépôt : `formation:products:set-origin`
(`src/Formation/Module02Origin/Command/SetProductOriginCommand.php`) avec `--dry-run`, périmètre explicite
(`--code`, `--only-empty`), idempotence, transaction et journal.

```bash
bin/console formation:products:set-origin France --only-empty --dry-run   # 1. simulation : liste ce qui changerait
bin/console formation:products:set-origin France --only-empty             # 2. exécution
bin/console formation:products:set-origin France --only-empty             # 3. rejouer : 0 modification (idempotent)
```

### Modèle de migration de données

```php
final class Version20261110090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HOTFIX-123 : origine manquante sur les produits de la catégorie huiles';
    }

    public function up(Schema $schema): void
    {
        // Périmètre précis et condition d'idempotence (WHERE ... IS NULL) : rejouable sans effet.
        $this->addSql("UPDATE sylius_product SET origin = 'Italie' WHERE origin IS NULL AND code LIKE 'OLIVE%'");
    }

    public function down(Schema $schema): void
    {
        // Inverse seulement si c'est sûr ; sinon documenter « restaurer la sauvegarde ».
        $this->addSql("UPDATE sylius_product SET origin = NULL WHERE code LIKE 'OLIVE%' AND origin = 'Italie'");
    }
}
```

### Check-list d'un hotfix de données

**Avant**
1. Reproduire sur un instantané **anonymisé** (points 3 et 4) et écrire le correctif.
2. Dérouler la simulation (`--dry-run` ou `doctrine:migrations:migrate --dry-run`) et **relire le nombre de lignes** : s'il est supérieur à l'attendu, s'arrêter.
3. Tester l'exécution, puis le retour arrière, sur une copie.
4. Relecture par un second développeur ; numéro de ticket dans la description.
5. Sauvegarde de la base juste avant (`scripts/formation/db-dump.sh` en local, snapshot ou `mysqldump` en production).

**Pendant**
6. Exécuter par la pipeline ou le runbook, pas depuis un poste. Pour un gros volume : par lots, hors des heures de pointe.
7. Surveiller les journaux et les files Messenger (`messenger:failed:show`).

**Après**
8. Requêtes de contrôle (comptages avant/après, échantillon vérifié à la main).
9. Contrôler **chaque site** : un correctif touche une base partagée par tous les channels (voir ci-dessous).
10. Vider les caches concernés (cache applicatif, cache HTTP par hostname) et consigner l'intervention.

### Spécificités du multi-sites

- Les clients et le catalogue sont **partagés** : une erreur de données se voit sur plusieurs sites. Limiter
  la portée par channel quand c'est voulu (`WHERE channel_code = 'SITE_FR'` sur les prix) et le dire dans la description.
- Les **prix** sont par channel (`sylius_channel_pricing`) : un correctif de prix cible un `channel_code`
  explicite, jamais « toutes les variantes ».
- Vérifier **chaque hostname** après correction, en commençant par un site pilote.

### Hotfix et Git

1. Branche `hotfix/<ticket>` créée **depuis le tag déployé en production**, pas depuis la branche de développement.
2. Correctif minimal, tests, pull request relue.
3. Version de correction (par exemple 1.4.1) déployée par la pipeline normale, avec sauvegarde et retour arrière.
4. Rapatriement dans la branche principale (merge ou cherry-pick) pour ne pas le perdre à la prochaine version.
5. Les migrations s'appliquent par ordre de version ; garder un horodatage de création récent et ne pas
   renommer une migration déjà déployée.

## 6. À ne jamais faire

- Charger des fixtures, ou lancer `sylius:fixtures:load`, en production (elles **purgent** les tables).
- Éditer la base de production à la main, ou « nettoyer » une ligne depuis un client SQL.
- Partager un dump de production non anonymisé, par messagerie, par Git ou par un dossier partagé.
- Copier la configuration des passerelles de paiement de la production vers un autre environnement
  (clés chiffrées avec la clé de l'environnement, et dangereuses ailleurs).
- Modifier une migration déjà exécutée quelque part : en écrire une nouvelle.
