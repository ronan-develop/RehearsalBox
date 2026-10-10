# CLAUDE.md — RehearsalBox & conventions

Gestion des disponibilités d'un local de répétition partagé entre groupes de musique (rock/punk/metal/prog). PHP pur (pas de framework) + JS vanilla + PDO/MySQL. Mobile-first, hébergement mutualisé visé.

Sommaire de référence. Lire avant toute intervention — suivre les liens pour le détail.

---

## Index

| Sujet                                   | Fichier                                                  |
|-----------------------------------------|----------------------------------------------------------|
| Architecture `src/`                     | [.claude/architecture.md](.claude/architecture.md)       |
| Audit : classes par dossier, cible par domaine | [.claude/audit-architecture.md](.claude/audit-architecture.md) |
| Commits, branches, `/git`               | [.claude/git-conventions.md](.claude/git-conventions.md) |
| Commandes (dev, tests, DB)              | [.claude/commands.md](.claude/commands.md)               |
| Méthodologie TDD                        | [.claude/tdd.md](.claude/tdd.md)                         |
| Délégation (`/dispatch`, `/split`, `/split-opus`) | [.claude/delegation.md](.claude/delegation.md)           |
| Modop : installer la délégation dans un autre projet | [.claude/modop-delegation.md](.claude/modop-delegation.md) |
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
- `config/message-keys.json` : clés du chiffrement des messages (#171), jamais committé ; **perdues, les messages sont illisibles** — voir `.claude/deploiement.md`

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

### Délégation

Une demande qui modifie du code et n'est pas triviale (au-delà de 1-2 fichiers) suit la procédure de `.claude/commands/dispatch.md` : choisir le mode (direct, Sonnet + Haiku, Opus + Haiku), l'annoncer en une ligne, le lancer. Jamais de Haiku sur l'authentification, les droits, la concurrence SQL, les transactions, les e-mails ni la sécurité. Détail : `.claude/delegation.md`.

### Refacto

Tout ticket qui modifie du code passe, tests verts et avant les commits, par la passe de refacto de `.claude/delegation.md` (carte en « Refacto », checklist de la colonne relue sur le diff, corrections dans le même ticket, résultat annoncé en une ligne). Quel que soit le mode, y compris en direct ou sans commande.

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
