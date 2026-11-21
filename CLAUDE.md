# CLAUDE.md

## Projet
Plateforme e-commerce **Sylius 2.3** (sylius-standard), PHP 8.3, Symfony, API Platform.
Instance locale **multi-sites** (un channel par site), voir `docs/formation/README.md`.

## Environnement (Docker)
- Démarrer : `make up` ; shell PHP : `make php-shell` (ou `docker compose exec php sh`).
- Console : toutes les commandes `php bin/console ...` de ce fichier se lancent dans le conteneur PHP, jamais avec le `php` de l'hôte (souvent 8.2) ; depuis l'hôte : `make console ARGS='...'` ou `scripts/formation/console.sh ...` (le fichier `bin/console` n'est pas exécutable sous Windows).
- Sites locaux : `http://localhost` (HQ), `http://fr.sylius.localhost`, `http://de.sylius.localhost`, `http://us.sylius.localhost`.
- Charger les données de développement : `php bin/console sylius:fixtures:load formation_multisite` (développement uniquement).

## Commandes de qualité
- Tout contrôler : `make qa` (ECS, PHPStan, PHPUnit, dans le conteneur php ; équivalent de `scripts/formation/qa.sh` lancé depuis le conteneur).
- Tests de la formation : `make test` (ou `vendor/bin/phpunit tests/Formation` dans le conteneur).
- Migrations : `php bin/console doctrine:migrations:diff` puis `doctrine:migrations:migrate` ; relire chaque migration avant de la committer.

## Règle d'extension (dans cet ordre)
1. Configuration (fichiers `config/packages/formation_*.yaml`).
2. Événement ou workflow (listeners minces dans `src/Formation/...`, la logique va dans un service testé).
3. Décorateur de service.
4. Surcharge de classe, en dernier recours (déjà faite : `src/Entity/Product/Product.php`).

## Organisation du code de formation
`src/Formation/ModuleNN<Nom>/` : un dossier par module de la formation (voir `docs/formation/README.md`).
Entités : `src/Entity/` (mapping Doctrine). Tests : `tests/Formation/`. Scripts : `scripts/formation/`.

## Agents IA
- Agents partagés dans `.claude/agents/` (explorer, backend-dev, test-writer, qa, reviewer, docs-writer) et permissions dans `.claude/settings.json` : voir `docs/formation/process-agentique.md`.
- Process : explorer en lecture seule, plan validé par un humain avant tout code, petits incréments testés, contrôle qualité, relecture, pull request.
- Les agents lancent les commandes dans le conteneur : `docker compose exec -T -e XDEBUG_MODE=off php ...` (les commandes de ce fichier sans préfixe se lancent ainsi ou dans `make php-shell`).
- Les fichiers de `.claude/`, `.github/`, `scripts/` et le `Makefile` s'exécutent chez quiconque clone le dépôt : le propriétaire est désigné par CODEOWNERS (revue exigée si le ruleset de `main` l'impose) et les réglages partagés ne contiennent ni hooks ni serveurs MCP.

## Interdits
- Ne pas modifier `vendor/` ni les migrations déjà appliquées.
- Ne jamais lancer `sylius:fixtures:load` hors développement.
- Aucun secret dans le code, les prompts ou Git (`.env.local`, clés JWT, clé de chiffrement des paiements).
- Ne pas versionner `compose.override.yml` (fichier local).

## Méthode de travail
- Plan d'abord, validé par un humain ; petits incréments ; tests verts avant de continuer.
- Fournir la version (Sylius 2.3.1) et la source (fichier du vendor ou doc) pour toute clé de configuration : ne pas en inventer.
