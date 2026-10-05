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
├── Mail/            ← MailRenderer : e-mail en HTML + texte (templates/mail/, gabarit commun layout.php)
└── Database/        ← ConnectionFactory (PDO), TransactionRunner
```

## E-mails (#157)

Chaque e-mail est **multipart** : `templates/mail/<nom>.html.php` (corps, inséré dans `layout.php`) et `<nom>.txt.php` (version texte). `MailRenderer::render('<nom>', $data)` retourne `['html' => …, 'text' => …]`. Règles : tables et **styles en ligne** (clients de messagerie), aucune police web ni image distante, **tout contenu dynamique échappé** avec `e()` (le contenu d'un e-mail peut venir d'un utilisateur), liens construits depuis `app.base_url`. Ajouter un e-mail = deux gabarits + un test dans `tests/Mail/MailRendererTest.php`.

## Principes appliqués

- **SRP** — un controller ne fait que dispatcher HTTP, la logique métier vit dans `Service/`
- **DIP** — chaque `Service`/`Repository` dépend d'une interface (`Contract/`), jamais d'une implémentation concrète
- **DRY** — CSRF, auth, échappement HTML chacun centralisés une fois (`CsrfTokenManager`, `AuthGuard`, helper `e()`)

## Brancher un composant Symfony

L'application n'est pas un projet Symfony, mais elle est **prête à en accueillir les composants** : le métier (`Service/`, `Entity/`) ne dépend que d'interfaces (ports) — `*RepositoryInterface`, `MailerInterface`, `ClockInterface`, `PasswordHasherInterface`, `SessionInterface` — et chaque implémentation (adaptateur) se lie dans `config/services.php`. Brancher un composant = l'ajouter à `composer.json` (dernière version, bibliothèque **maintenue**), écrire ou lier l'adaptateur dans `services.php`, jamais de classe concrète Symfony dans une signature du métier quand une interface existe.

### Règles pour rester « branchable » (sans réécrire l'architecture)

À respecter dans tout nouveau code ; rien à refaire d'avance, seulement ne pas creuser l'écart.

1. **Le métier ignore HTTP** : aucun `Request`/`Response`/session/`$_*` dans `Service/`, `Entity/`, `Repository/` (vérifié : c'est le cas). Un service reçoit des scalaires ou des entités et l'**identité de l'acteur en paramètre** (`$actorUserId`), jamais l'objet d'une requête : un State Provider, un contrôleur Symfony ou une commande console l'appellent de la même façon.
2. **Autorisation dans le service** (IDOR), jamais seulement dans le contrôleur : tout adaptateur (API Platform comprise) hérite des mêmes garde-fous.
3. **Exceptions métier sans dépendance framework** (`AccessDeniedException`, `*ValidationException` étendent `\RuntimeException` / `\InvalidArgumentException`) : le noyau actuel les traduit en 403/422, un listener Symfony le ferait de la même façon.
4. **Dépendances par interface** : le temps (`ClockInterface`), les dépôts, le hasher, la session, le moteur de gabarits. Écarts connus et acceptés pour l'instant : `TransactionRunner` et `MailRenderer` sont des classes concrètes (à passer derrière une interface le jour où un second adaptateur existe, pas avant).
5. **Un contrôleur mince** : lire la requête, appeler un service, présenter. La présentation JSON (tableaux `*ToArray`) est le candidat naturel à extraire en **objets de lecture** (read models) réutilisables par les pages, l'API et plus tard un sérialiseur ou des ressources API Platform.
6. **Constructeurs explicites et typés, pas de localisateur de services** dans les classes métier : l'autowiring Symfony n'aurait rien à deviner.
7. **Routes en données** (`config/routes.php`) et migrations en fichiers SQL : convertibles mécaniquement vers le routage et Doctrine Migrations.

| Composant | Statut | Remarque |
|---|---|---|
| `symfony/mailer`, `mime` | En place | `MailerInterface` injecté ; gabarits dans `templates/mail`. |
| `symfony/clock` | En place (#170) | `ClockInterface` (PSR-20) lié au conteneur ; `MockClock` en test. Les services existants migrent au fil des tickets (paramètre `?DateTimeImmutable $now` → horloge injectée), la messagerie l'utilise d'emblée. |
| `symfony/string` | À considérer | Remplacerait `Support\Slug` / `Initials` par un code maintenu (unicode). Gain faible. |
| `symfony/security-csrf` | À évaluer | Remplacerait `CsrfTokenManager` (code maison sur un point de sécurité) ; dépendances à mesurer. |
| `symfony/rate-limiter` | Écarté pour l'instant | Nos limites comptent des lignes déjà stockées (testé, sans cache). À revoir si elles se multiplient. |
| `symfony/validator` | Écarté | Trop de dépendances pour trois petites politiques de saisie. |
| `symfony/mercure` | Impossible | Demande un hub permanent, impossible sur mutualisé (polling à la place). |
| `routing`, `http-foundation`, `http-kernel` | Écarté | Remplacer le socle minimal = réécrire tous les contrôleurs, pour un gain faible. |
| `API Platform` | À évaluer (https://github.com/ronan-develop/RehearsalBox/issues/172) | Utile surtout pour une API publique ou un client natif ; demande le framework Symfony complet. Spike borné avant toute décision. |

**Mises à jour** : `.github/dependabot.yml` propose les PR (composer + GitHub Actions) chaque semaine ; `composer audit` tourne en CI et avant chaque déploiement. Symfony 8.x est une branche à versions mineures courtes (passer à la mineure suivante à chaque sortie) ; la branche LTS reste une option si le rythme pèse.

## Règle critique — pas d'ORM

Aucune couche n'échappe le SQL à ta place : chaque repository écrit ses requêtes en PDO préparé (`PDO::ATTR_EMULATE_PREPARES => false`). Voir le point clé sur la concurrence ci-dessous et le plan de sécurité pour le détail des règles (injection, IDOR).

**À ne jamais faire :**

- Construire un fragment SQL avec une valeur utilisateur concaténée
- Laisser un `Service` construire du SQL — cette responsabilité reste entièrement dans `Repository/`
- Interpoler un nom de colonne/table venant de `$_GET`/`$_POST` (passer par une whitelist statique)

## Contrôles d'accès (IDOR)

- **Où** : dans le `Service/` (jamais dans le contrôleur ni le template), à partir de l'utilisateur de la session. Le groupe ou le propriétaire d'une ressource est **toujours déduit de la base** (créneau, demande, document), jamais d'un paramètre ou du corps de la requête.
- **Quoi** : appartenance (`isMember`) pour lire, rôle gestionnaire (`roleOf`) pour modifier un groupe ou ses documents, rôle admin (`AuthGuard::requireRole`) pour l'administration. Un membre du groupe demandeur ne répond pas à sa propre demande.
- **Réponse** : un objet interdit et un objet inexistant renvoient la **même réponse** (403, même message) pour les ressources de groupe, document et demande ; 401 si anonyme. Ne jamais distinguer « n'existe pas » de « pas à vous » (énumération des identifiants). Les routes admin gardent leurs codes propres.
- **Test** : toute nouvelle route à identifiant s'ajoute à `tests/Security/IdorMatrixTest.php` (acteurs refusés, identifiant inexistant, cas limites).

## Messagerie entre groupes (#153, #169)

- **Modèle** : `conversations` (groupe initiateur, groupe visé, **titre facultatif**), `conversation_messages` (auteur, texte brut, `is_system` pour les lignes générées comme « a renommé la conversation »), `conversation_states` (par personne : `last_read_at`, `typing_at`) — migrations 014 et 015. Le « non lu » se déduit de `last_read_at`.
- **Archivage dérivé** : une conversation sans message depuis 30 jours (`ConversationService::ARCHIVE_AFTER`) est « archivée » pour tout le monde ; un nouveau message la ramène. Rien n'est stocké ni planifié (pas de cron). Le service calcule la limite avec `ClockInterface` et la passe au dépôt.
- **Accès** : tout membre de l'un des deux groupes lit, répond et renomme (`ConversationService`). Pour démarrer, il faut appartenir au groupe émetteur choisi ; un groupe ne s'écrit pas à lui-même. Auteur et utilisateur viennent **toujours de la session**. Fil interdit, inexistant ou identifiant mal formé (`StrictId`) : même 403 « Accès refusé. », API **et** pages.
- **Routes** : API `GET/POST /api/conversations`, `GET|PATCH /api/conversations/{id}` (`?after=<id>` = lecture incrémentale), `POST …/messages`, `POST …/typing` ; pages `/messages` et `/messages/{id}` (une route par conversation, même gabarit, contenu servi par l'API).
- **Quasi temps réel sans push** : polling (fil ouvert ≈ 4 s, ralenti jusqu'à 15 s sans activité ; liste ≈ 30 s ; arrêt onglet caché). « Vu par » = lecteurs dont `last_read_at` ≥ date de mon dernier message ; « écrit… » = signal limité à un toutes les 2 s (dépôt) valable 5 s, jamais pour soi-même.
- **Pastille** : initiales de l'auteur, couleur du groupe d'appartenance parmi les deux groupes de la conversation (neutre si ambigu) ; aucun changement de schéma, aucun identifiant interne exposé (`ConversationPresenter`).
- **Saisie** : `ConversationInputPolicy` (titre ≤ 150 caractères sans caractère de contrôle, message ≤ 5 000), limite de 30 messages par heure et par personne (429). Le texte n'est jamais inséré en HTML côté client (`textContent`).
- **Front** : `chat-model.js` (logique pure), `chat-api.js` (appels, CSRF), `chat-view.js` (rendu), `chat.js` (contrôleur) ; mise en page à la Signal (mobile : liste OU fil ; ≥ 900 px : deux colonnes).
- **Pas d'e-mail** à chaque message pour l'instant (#171 : chiffrement ; notification e-mail : ticket à part).

## Point clé — concurrence sur les créneaux libérés

`MysqlSlotExceptionRepository::claim()` porte un `UPDATE ... WHERE status='liberee'` atomique, jamais un `SELECT` puis `UPDATE` séparés. Un `rowCount() === 0` signifie "déjà pris par quelqu'un d'autre" → 409, pas d'exception. C'est le test le plus important du projet.

## Modèle d'interaction

Navigation entre pages : rendu serveur classique (`PageController`, GET, templates PHP). Toute écriture (login, claim, CRUD admin) : XHR vers `Api/*Controller`, jamais de submit de formulaire natif avec reload. Détail complet dans le plan de conception initial (hors dépôt, conversation de cadrage projet).

## Génération de fichiers

|Fichier|Méthode|
|-|-|
|Migration SQL|écrite à la main dans `database/migrations/`, appliquée par `bin/migrate.php`|
|Entité/Service/Repository/Test|générés directement (voir `.claude/templates/*.stub` pour le squelette)|
