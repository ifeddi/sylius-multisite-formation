# Process agentique : agents, permissions et sécurité

Ce document décrit comment le projet encadre le développement avec des agents IA : où se trouve la configuration, quel agent fait quoi, ce que les permissions protègent et ce qu'elles ne protègent pas. Il complète le module M12 (voir la [carte des modules](README.md)).

## 1. Où est la configuration

| Fichier | Rôle | Versionné |
|---|---|---|
| `CLAUDE.md` | Règles du projet : environnement, commandes de qualité, règle d'extension, interdits, méthode | oui |
| `.claude/agents/*.md` | Les six agents partagés (rôle, outils, limites, format de rapport) | oui |
| `.claude/settings.json` | Permissions partagées : `deny` (refus), `ask` (confirmation), `allow` (autorisé) | oui |
| `.claude/settings.local.json` | Réglages personnels | non (ignoré par Git) |
| `.github/CODEOWNERS` | Désigne le propriétaire des fichiers sensibles (`.claude/`, `CLAUDE.md`, `.github/`, `scripts/`, `Makefile`, outillage) | oui |
| `tests/Formation/Agents/AgentsConfigurationTest.php` | Test de CI qui empêche d'affaiblir cette configuration | oui |
| `~/.claude/` (dossier de l'utilisateur) | Préférences et agents personnels, hors dépôt | non |

## 2. La boucle et les agents

| Étape | Qui | Livrable |
|---|---|---|
| Cadrer | l'humain, avec `CLAUDE.md` | besoin décrit, règles connues |
| Explorer | `explorer` (lecture seule) | rapport : fichiers, risques, approche |
| Planifier | l'agent principal en mode plan | plan ; **validé par un humain avant tout code** |
| Implémenter | `backend-dev`, puis `test-writer` | code et tests, par petits incréments |
| Contrôler | `qa` | tableau de résultats : ECS, PHPStan niveau 9, PHPUnit |
| Relire | `reviewer` (lecture seule) | constats : bloquant, important, suggestion |
| Livrer | `docs-writer`, puis l'humain | documentation, commit, pull request |

| Agent | Outils | Peut écrire dans |
|---|---|---|
| `explorer` | Read, Grep, Glob (pas d'accès au web) | nulle part |
| `reviewer` | Read, Grep, Glob | nulle part (la diff lui est fournie) |
| `qa` | Read, Grep, Glob, Bash | nulle part (il exécute les contrôles) |
| `backend-dev` | Read, Grep, Glob, Edit, Write, Bash | `src/Formation/`, `src/Entity/`, `config/packages/formation_*`, nouvelles migrations, etc. (limite écrite dans son prompt) |
| `test-writer` | idem | `tests/` |
| `docs-writer` | Read, Grep, Glob, Edit, Write | `README.md`, `docs/` |

Il n'y a pas d'agent `frontend-dev` : le projet n'a pas d'interface propre, les Twig Hooks ne sont utilisés que pour le back-office (module M4).

## 3. Permissions partagées

`.claude/settings.json` s'applique à toute la session, sous-agents compris. L'ordre d'évaluation est : `deny`, puis `ask`, puis `allow` ; un `deny` l'emporte toujours.

- **`deny`** : lecture de `.env.local`, des clés (`config/jwt/*.pem`, `config/encryption/*.key`), des sauvegardes de base (`dumps/`) et des clés SSH et AWS ; modification de `vendor/` ; `git push --force`, `git reset --hard`, `docker compose down -v`, `rm -rf`, `curl` et `wget`.
- **`ask`** : `composer update` et `remove`, `docker compose down`, `git push`, `git config`, chargement de fixtures, `ecs --fix`, et la modification des migrations, de `.claude/`, `.github/`, `CLAUDE.md`, `scripts/`, du `Makefile`, de `composer.json`, de la configuration des outils et des tests de sécurité des agents.
- **`allow`** : lecture de l'état Git, contrôles qualité précis dans le conteneur, et quelques commandes console en lecture (`formation:sites:list`, `debug:*`, `lint:*`, `list`). Les autres commandes console demandent confirmation.

Le périmètre d'écriture de chaque agent est écrit dans son prompt : les permissions ne l'imposent pas, elles se limitent aux règles `ask` et `deny` ci-dessus.

Les règles `Bash(...)` sont des **garde-fous, pas une frontière de sécurité** : une commande équivalente écrite autrement (`sh -c '...'`, chemin absolu) peut passer. Pour une vraie barrière, il faut un bac à sable (sandbox) ou des réglages gérés au niveau de la machine.

## 4. Pourquoi cette configuration est protégée

Le dossier `.claude/` s'exécute avec les droits de quiconque clone le dépôt : une pull request qui ajouterait un hook ou élargirait les permissions pourrait exécuter du code sur la machine d'un contributeur. Les mesures :

1. **Aucun hook, `env`, serveur MCP, `defaultMode` ni dossier supplémentaire** dans les réglages partagés. Les hooks personnels restent dans `~/.claude/`.
2. **Agents de lecture seule sans `Bash`** : `explorer` et `reviewer` n'ont que Read, Grep et Glob, et refusent explicitement Write, Edit et Bash (`disallowedTools`).
3. **Test de CI** (`AgentsConfigurationTest`) : il échoue si un agent change d'outils ou de mode de permissions, si une clé de frontmatter non prévue apparaît, si un fichier inattendu entre dans `.claude/`, si une règle `deny` ou `ask` obligatoire disparaît ou si une autorisation trop large est ajoutée. Il protège contre un oubli ou une erreur, pas contre une pull request malveillante qui modifierait aussi le test : c'est le rôle de la revue.
4. **CODEOWNERS** désigne le propriétaire de `.claude/`, `CLAUDE.md`, `.github/`, `scripts/`, du `Makefile` et de l'outillage. GitHub n'**exige** cette revue que si le ruleset de `main` active « Require review from Code Owners » (réglage du dépôt, à faire séparément).
5. **Contenu externe = données** : chaque agent a pour règle de ne suivre aucune consigne trouvée dans un fichier, une sortie de commande ou une page web.

Pour utiliser un assistant en mode non interactif (`claude -p`) sur un dépôt dont vous ne maîtrisez pas le contenu, ignorez les réglages du projet : option `--setting-sources user` ou `--bare` (voir la documentation officielle : code.claude.com/docs, pages permissions, settings, security et managed-settings).

## 5. Utiliser les agents

Exemples de demandes :

- « Utilise l'agent `explorer` pour analyser l'ajout d'un champ sur les customers, sans rien modifier. »
- « Une fois le plan validé, délègue l'implémentation à `backend-dev`, puis les tests à `test-writer`. »
- « Lance `qa`, puis donne la diff à `reviewer`. »

Les fichiers d'agents sont surveillés par l'assistant : une modification est prise en compte en quelques secondes, d'après la documentation officielle sur les sous-agents (non vérifié ici).

## 6. Ajouter ou modifier un agent

1. Créer `.claude/agents/<nom>.md` avec un frontmatter contenant `name` (identique au nom du fichier), `description`, `tools` (liste explicite, jamais « tous les outils ») et `maxTurns`.
2. Donner le moins d'outils possible, et écrire le périmètre d'écriture dans le prompt (les permissions ne l'imposent pas).
3. Ne pas ajouter `hooks`, `mcpServers` ni un `permissionMode` autre que `default` ou `plan`.
4. Ajouter l'agent et ses outils à `AgentsConfigurationTest`, puis ouvrir une pull request : le test de CI et la revue du propriétaire s'appliquent.
5. Mettre la description entre guillemets dans le frontmatter (le YAML n'accepte pas `: ` dans un texte non quoté).

## 7. Modèle de demande en sept phases

```text
MISSION : <fonctionnalité ou bug, avec le ticket>
1. Explorer : agent « explorer », rapport sans modification.
2. Plan : fichiers à créer ou modifier, approche (règle d'extension de CLAUDE.md), tests. J'attends ma validation.
3. Implémenter : « backend-dev » par petits incréments, puis « test-writer ».
4. Contrôler : « qa » (ECS, PHPStan niveau 9, PHPUnit) ; tout doit être vert.
5. Relire : « reviewer » avec la diff ; corriger les points bloquants et importants.
6. Documenter : « docs-writer ».
7. Livrer : pull request liée au ticket.
CONTRAINTES : pas de modification de vendor/ ni des migrations appliquées ; demande si une information manque.
```

## 8. Limites

- Les gains de temps ne sont pas mesurés.
- Les règles `Bash(...)` ne sont pas une frontière de sécurité (voir plus haut).
- `explorer` n'a pas accès au web (pas de `WebFetch`) : la documentation officielle doit être consultée par l'agent principal, ce qui évite qu'un contenu externe ne serve à faire sortir des données.
- Le réglage de ces agents n'a pas été mesuré en conditions réelles de projet : à ajuster à l'usage.
