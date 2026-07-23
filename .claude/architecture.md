# Architecture `src/`

Pas de framework : PHP pur + JS vanilla + PDO. Garde-fou permanent : pas d'usine à gaz, pas de "réinventer Symfony/Laravel en miniature" — routeur, container DI et renderer de vues restent volontairement minimaux (quelques dizaines de lignes chacun, pas d'auto-wiring par réflexion, pas de système de plugins).

## Structure

```txt
src/
├── Http/           ← Request/Response/JsonResponse/RedirectResponse
├── Routing/        ← Router, Route, RouteCollection (routes "pages" vs "api" séparées)
├── Container/       ← DI container maison, définitions explicites, pas d'auto-wiring
├── Controller/
│   ├── PageController.php   ← rend du HTML, GET only (navigation)
│   └── Api/                 ← rend du JSON uniquement, porte toute action d'écriture
├── Service/         ← logique métier (AuthService, AvailabilityService, SlotService, GroupService)
├── Repository/      ← accès PDO, implémentent les interfaces de Repository/Contract
├── Entity/          ← entités simples, sans comportement DB (pas de Doctrine)
├── Security/        ← PasswordHasher, Session, CsrfTokenManager, AuthGuard
├── View/            ← TemplateRendererInterface / PhpTemplateRenderer (include PHP natif)
└── Database/        ← ConnectionFactory (PDO), TransactionRunner
```

## Principes appliqués

- **SRP** — un controller ne fait que dispatcher HTTP, la logique métier vit dans `Service/`
- **DIP** — chaque `Service`/`Repository` dépend d'une interface (`Contract/`), jamais d'une implémentation concrète
- **DRY** — CSRF, auth, échappement HTML chacun centralisés une fois (`CsrfTokenManager`, `AuthGuard`, helper `e()`)

## Règle critique — pas d'ORM

Aucune couche n'échappe le SQL à ta place : chaque repository écrit ses requêtes en PDO préparé (`PDO::ATTR_EMULATE_PREPARES => false`). Voir le point clé sur la concurrence ci-dessous et le plan de sécurité pour le détail des règles (injection, IDOR).

**À ne jamais faire :**

- Construire un fragment SQL avec une valeur utilisateur concaténée
- Laisser un `Service` construire du SQL — cette responsabilité reste entièrement dans `Repository/`
- Interpoler un nom de colonne/table venant de `$_GET`/`$_POST` (passer par une whitelist statique)

## Point clé — concurrence sur les créneaux libérés

`MysqlSlotExceptionRepository::claim()` porte un `UPDATE ... WHERE status='liberee'` atomique, jamais un `SELECT` puis `UPDATE` séparés. Un `rowCount() === 0` signifie "déjà pris par quelqu'un d'autre" → 409, pas d'exception. C'est le test le plus important du projet.

## Modèle d'interaction

Navigation entre pages : rendu serveur classique (`PageController`, GET, templates PHP). Toute écriture (login, claim, CRUD admin) : XHR vers `Api/*Controller`, jamais de submit de formulaire natif avec reload. Détail complet dans le plan de conception initial (hors dépôt, conversation de cadrage projet).

## Génération de fichiers

|Fichier|Méthode|
|-|-|
|Migration SQL|écrite à la main dans `database/migrations/`, appliquée par `bin/migrate.php`|
|Entité/Service/Repository/Test|générés directement (voir `.claude/templates/*.stub` pour le squelette)|
