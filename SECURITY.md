# Politique de sécurité

## Signaler une vulnérabilité

Utilisez le signalement privé de GitHub : onglet **Security**, puis **Report a vulnerability**. Ne publiez pas de détails dans une issue publique.

Ce dépôt est un projet de formation : il n'a pas de version supportée en production. Une vulnérabilité dans Sylius lui-même doit être signalée à l'équipe Sylius ([sylius.com/security](https://sylius.com/security)).

## Ce que le dépôt contient et ne contient pas

- **Aucun secret réel.** Les fichiers `.env.local`, les clés JWT de l'environnement et la clé de chiffrement des paiements ne sont jamais versionnés (voir `.gitignore`).
- **Valeurs de test publiques** : `config/jwt/private-test.pem`, `config/jwt/public-test.pem`, `config/encryption/test.key` et la phrase de passe de test du fichier `.env` sont celles de Sylius-Standard. Elles servent aux tests uniquement et sont autorisées explicitement dans `.gitleaks.toml`.
- **Comptes de développement** : ceux de `docs/formation/README.md` sont créés par les fixtures locales. Ne jamais les utiliser en staging ou en production.

## Contrôles automatiques

- gitleaks sur tout l'historique, à chaque push, pull request et chaque semaine.
- GitHub secret scanning avec push protection.
- `composer audit`, revue des dépendances et Dependabot (composer, npm, GitHub Actions).
- ECS, PHPStan (niveau 9) et PHPUnit à chaque pull request.
