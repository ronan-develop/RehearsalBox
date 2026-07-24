# CLAUDE.md — RehearsalBox & conventions

Gestion des disponibilités d'un local de répétition partagé entre groupes de musique (rock/punk/metal/prog). PHP pur (pas de framework) + JS vanilla + PDO/MySQL. Mobile-first, hébergement mutualisé visé.

Sommaire de référence. Lire avant toute intervention — suivre les liens pour le détail.

---

## Index

| Sujet                                   | Fichier                                                  |
|-----------------------------------------|----------------------------------------------------------|
| Architecture `src/`                     | [.claude/architecture.md](.claude/architecture.md)       |
| Commits, branches, `/git`               | [.claude/git-conventions.md](.claude/git-conventions.md) |
| Commandes (dev, tests, DB)              | [.claude/commands.md](.claude/commands.md)               |
| Méthodologie TDD                        | [.claude/tdd.md](.claude/tdd.md)                         |
| Design frontend                         | [.claude/frontend.md](.claude/frontend.md)               |
| Layout CSS, mobile-first                | [.claude/layout.md](.claude/layout.md)                   |
| CI/CD, suivi                            | [.claude/cicd.md](.claude/cicd.md)                       |
| Déploiement (WIP, hébergeur non choisi) | [.claude/deploiement.md](.claude/deploiement.md)         |

---

## Règles critiques — toujours actives

### Pas d'usine à gaz

Le routeur, le container DI et le renderer de vues restent volontairement minimaux — pas d'auto-wiring par réflexion, pas de système de plugins, pas de couche d'abstraction sans second cas d'usage réel. Si une pièce du socle technique commence à ressembler à Symfony/Laravel en miniature, c'est un signal pour s'arrêter et simplifier.

### Secrets

- Ne jamais commiter de secrets — relire le diff stagé avant chaque commit
- `config/config.local.php` : valeurs sensibles, jamais committé (`.gitignore`)

### Git

- Jamais de commit direct sur `main`
- Commits atomiques — jamais `git add .` en un bloc
- Confirmation obligatoire avant : `git push`, merge, ouvrir/fermer une PR, supprimer une branche

### TDD

- Toujours RED → GREEN → REFACTOR — ne jamais écrire le code avant le test qui le justifie
- Couverture attendue : accès non autorisé (IDOR inclus), happy path, cas limites, rollback/erreur
- Exception : classes purement structurelles sans branche logique (value objects, entités sans comportement)

### Pas d'ORM — SQL préparé partout

Chaque accès aux données passe par un repository PDO écrit à la main (prepared statements, `EMULATE_PREPARES` désactivé). Jamais de fragment SQL construit avec une valeur utilisateur concaténée. Voir `.claude/architecture.md` pour le détail et le point clé sur la concurrence des créneaux libérés (`UPDATE ... WHERE status='liberee'` atomique).

### Async systématique

Toute action qui modifie des données (login, claim, CRUD admin) passe par `fetch()`/XHR, jamais par un submit de formulaire natif avec rechargement de page. La navigation entre pages reste du rendu serveur classique.

---

## Commandes essentielles

```bash
php -S localhost:8000 -t public public/index.php   # serveur de dev
php bin/migrate.php                                  # migrations
./vendor/bin/phpunit --colors=always                 # tests PHP
npm test                                             # tests JS
composer audit                                       # avant tout déploiement
```
