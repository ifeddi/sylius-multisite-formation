# Simuler l'implémentation d'un ticket

Ce guide permet de **simuler un ticket de bout en bout sur une machine de développement**, avec le [process agentique](process-agentique.md) du projet. Les tickets sont ceux de la [roadmap](roadmap.md).

> **Statut** : ce guide n'a été écrit que depuis un seul poste, et seules les étapes 1 à 2 de la boucle (exploration, plan) ont été éprouvées de bout en bout. Il doit être validé sur une machine vierge (ticket S1-5) : signalez tout écart avec le modèle de ticket « Bug ».

## 1. Prérequis

| Élément | Remarque |
|---|---|
| Docker (Compose v2) | Prévoir environ 2,5 à 3 Go de disque (images et volumes) |
| Git | Pour cloner et créer une branche |
| bash | Git Bash ou WSL sous Windows ; natif sous macOS et Linux |
| `make` | Absent de Git Bash par défaut : sans lui, utiliser les équivalents `bash scripts/formation/...` indiqués plus bas |
| GitHub CLI `gh` (facultatif) | Pour lire un ticket (`gh issue view N`) et ouvrir une pull request |
| Un assistant IA capable de lire `.claude/agents/` | Les agents du projet sont dans `.claude/agents/` |
| Ports 80 et 3306 libres | Un serveur local (XAMPP, MySQL, IIS) qui les occupe empêche le démarrage de nginx et de MySQL |

Le PHP du poste n'est **pas** utilisé : toutes les commandes PHP passent par le conteneur (PHP 8.3).

## 2. Mettre en place le poste

```bash
git clone https://github.com/ifeddi/sylius-multisite-formation.git
cd sylius-multisite-formation
bash scripts/formation/init-local.sh                          # démarre Docker, installe ce qui manque, charge le jeu de données
bash scripts/formation/console.sh formation:sites:list        # doit afficher les quatre sites
```

Avec `make`, la deuxième commande s'écrit `make console ARGS='formation:sites:list'`.

La première exécution est longue : installation des dépendances puis chargement des fixtures (**plusieurs minutes**, de l'ordre de 3 à 4 minutes pour les fixtures seules).

Les sites répondent sur `http://localhost` (HQ), `http://fr.sylius.localhost`, `http://de.sylius.localhost` et `http://us.sylius.localhost`. Les comptes de test sont dans le [README de la formation](README.md).

**Code de Sylius pour l'agent `explorer`.** Il ne dispose que de `Read`, `Grep` et `Glob` : il lit `vendor/sylius/` sur le poste, il ne peut pas interroger le conteneur.
- Si le projet est monté dans le conteneur (modèle `compose.override.dist.yml`), le `vendor/` du poste est rempli par `init-local.sh`.
- Si `vendor/` est dans un volume Docker (réglage local de l'auteur), le dossier du poste est vide : copiez-le avec `docker compose cp php:/srv/sylius/vendor/sylius ./vendor/sylius` (lecture seule pour l'agent, ne rien y modifier ni le committer).

## 3. Choisir un ticket

- Les tickets sont les issues des jalons **Sprint 1** et **Sprint 2**.
- Prenez un ticket « prêt » (contexte, objectif, critères d'acceptation, pistes).
- Le label `stretch` indique un ticket optionnel ; `fichiers-protégés` indique que chaque modification demande une validation humaine (voir plus bas).
- **Premier essai complet** : prenez un ticket **non protégé**, par exemple S1-3 ([#12](https://github.com/ifeddi/sylius-multisite-formation/issues/12)). Le ticket guidé S1-2 (section 9) est protégé : la simulation s'y arrête au plan.

## 4. Créer une branche

```bash
git switch -c feat/s1-3-fournisseur-produit
```

Une branche par ticket, nommée avec le code du ticket. Ne travaillez pas directement sur `main` : selon la configuration du dépôt (ruleset de `main`), les pushes directs sont refusés.

## 5. La boucle agentique

Pour chaque étape, voici une demande type à adapter. Les réponses de l'assistant varient : jugez-les sur leur contenu (fichiers et lignes cités), pas sur leur forme.

1. **Explorer** (agent `explorer`, lecture seule)
   Donnez-lui le texte du ticket : copiez-le dans la conversation, ou lancez `gh issue view N` (l'agent `explorer` n'a ni accès web ni Bash ; le shell est lancé par l'agent principal, avec confirmation).
   « Utilise l'agent `explorer` pour analyser ce ticket : fichiers impactés, points d'extension Sylius, risques, approche recommandée. Ne modifie rien. »
2. **Planifier** (agent principal, en mode plan si votre outil en propose un)
   « À partir du rapport, propose un plan : fichiers à créer ou modifier, approche (règle d'extension de `CLAUDE.md`), tests à écrire. J'attends ma validation. »
   **Ne passez pas à l'étape suivante sans avoir validé le plan.**
3. **Implémenter** (agents `backend-dev` puis `test-writer`)
   « Le plan est validé. Délègue l'implémentation à `backend-dev` par petits incréments, puis les tests à `test-writer`. »
4. **Contrôler** (agent `qa`)
   « Lance `qa` et donne-moi le tableau de résultats. » Tout doit être vert : ECS, PHPStan niveau 9, PHPUnit.
5. **Relire** (agent `reviewer`)
   Les fichiers non suivis n'apparaissent pas dans `git diff` : lancez d'abord `git add -N .` (ajout « intent-to-add »), puis donnez la sortie de `git diff` à `reviewer`. Corrigez les points bloquants et importants.
6. **Documenter** (agent `docs-writer`)
   « Mets à jour la documentation concernée par le ticket. »
7. **Livrer**

   ```bash
   git add -A && git commit -m "Titre du changement (#N)"
   git push -u origin feat/s1-3-fournisseur-produit
   gh pr create --fill       # ou depuis l'interface GitHub, avec le modèle de pull request
   ```

   La description de la pull request contient `Closes #N`.

Règles à garder en tête :
- les commandes PHP se lancent dans le conteneur (`bash scripts/formation/console.sh ...`, `docker compose exec -T -e XDEBUG_MODE=off php vendor/bin/...`) ;
- aucun secret dans le code ni dans les messages ;
- un agent « vérifie, ne croit pas » : demandez le fichier et la ligne pour chaque affirmation.

## 6. Les tickets « protégés »

Un ticket `fichiers-protégés` touche `.github/`, `scripts/`, le `Makefile`, `compose*.yml`, `.claude/` ou `CLAUDE.md`. Le périmètre de l'agent `backend-dev` (défini dans son prompt) exclut ces fichiers, et les règles `ask` de `.claude/settings.json` demandent une confirmation pour plusieurs d'entre eux : validez chaque modification une par une, ou arrêtez la simulation au plan. Côté dépôt, CODEOWNERS désigne le propriétaire comme relecteur de ces chemins.

Certains tickets ne peuvent pas être simulés pour de bon : un devcontainer (S1-4) ou un workflow de CI (S2-5) ne se vérifient que sur la plateforme concernée. Dans ce cas, arrêtez-vous au plan.

## 7. Simuler sans publier

Vous pouvez faire tout le travail en local, sans `git push`. Pour proposer le résultat, poussez la branche sur **votre fork** et ouvrez la PR depuis celui-ci ; sur le dépôt d'origine, selon la configuration de `main`, la revue du propriétaire peut être exigée.

Pour supprimer la branche après un essai :

```bash
git switch main
git branch -D feat/s1-3-fournisseur-produit
```

Cela ne remet pas la base de données à zéro : les migrations appliquées pendant l'essai y restent. Pour repartir d'une base propre :

```bash
bash scripts/formation/init-local.sh --reset
```

(la base est supprimée et recréée, puis le jeu de données est rechargé).

## 8. Dépannage

| Symptôme | Cause et solution |
|---|---|
| « Le conteneur php ne tourne pas » | Lancer `make up` (ou `docker compose up -d`) |
| « docker compose est indisponible » | Docker n'est pas installé ou pas démarré |
| `make : command not found` | Utiliser les équivalents `bash scripts/formation/...` |
| « Composer detected issues in your platform … PHP >= 8.3.0 » | La commande a été lancée avec le PHP du poste : passer par `scripts/formation/console.sh` |
| `502 Bad Gateway` après un redémarrage de php | `docker compose restart nginx` |
| nginx ou MySQL ne démarre pas | Port 80 ou 3306 déjà utilisé sur le poste |
| ECS signale des fins de ligne sur des fichiers existants (Windows) | Limiter ECS à `src/Formation tests/Formation` |

Autres cas : [Dépannage du README de la formation](README.md). Pour signaler un défaut de ce guide : modèle de ticket « Bug ».

## 9. Ticket guidé, afficher l'origine du produit (S1-2)

Ticket : [#11](https://github.com/ifeddi/sylius-multisite-formation/issues/11), taille M, **protégé** (la simulation s'arrête au plan). Objectif : afficher « Origine : *pays* » sur la fiche produit de la boutique, avec un Twig Hook.

**Ce que l'exploration doit trouver** (pour vérifier votre rapport, sans la solution complète) :
- le Twig Hook de la fiche produit de la boutique et sa priorité, dans le code de Sylius ;
- le fichier de configuration du projet qui sert de modèle pour déclarer un hook (`config/packages/formation_m04_twig_hooks.yaml`) ;
- que le champ `origin` existe déjà et comment il est exposé ;
- la clé de traduction existante et les langues couvertes ;
- le risque lié au périmètre des agents (`backend-dev` n'écrit pas dans `templates/shop/`) : le ticket est donc « protégé ».

**Comment vérifier le résultat** : ouvrir la fiche du produit `OLIVE_OIL` sur le site français, vérifier qu'« Origine : Italie » s'affiche, puis qu'un produit sans origine n'affiche rien et qu'une origine contenant du HTML est échappée.

## 10. Ce qui n'existe pas encore

- Cibles `make qa` et `make test` : ticket S1-1 (en attendant, lancer l'agent `qa`, ou `scripts/formation/qa.sh` depuis le shell du conteneur : `docker compose exec php sh`).
- Devcontainer : ticket S1-4.
- Validation de ce guide sur une machine vierge : ticket S1-5. Les étapes 3 à 7 de la boucle n'ont pas encore été éprouvées de bout en bout.
