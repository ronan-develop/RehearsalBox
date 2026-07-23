Ready for review
Select text to add comments on the plan
RehearsalBox — Plan de conception (PHP pur / JS vanilla / CSS maison)
Contexte
Le repo est actuellement vide : il s'agit d'une conception from scratch pour une application de gestion de local de répétition partagé entre plusieurs groupes de musique (rock/punk/metal/prog). Chaque groupe a déjà un créneau récurrent fixe — le vrai besoin fonctionnel n'est PAS un système de réservation classique, mais la visualisation des disponibilités occasionnelles (quand un groupe libère exceptionnellement son créneau). L'app sera hébergée sur un serveur mutualisé, consultée quasi exclusivement sur mobile, et doit offrir une interface soignée pour un public rock/punk/metal/prog.

Décisions validées par l'utilisateur :

Stack : PHP pur (pas de framework) + JS vanilla (pas de React/Vue) + CSS maison (pas de Tailwind/Bootstrap), MySQL/MariaDB via PDO.
Architecture propre malgré l'absence de framework : SOLID/SRP/DRY, interfaces, services, repositories, factories, DI container maison.
Garde-fou explicite : pas d'usine à gaz, pas de "réinventer un framework". Le routeur, le container DI et le renderer de vues restent volontairement minimaux (quelques dizaines de lignes chacun, pas d'auto-wiring par réflexion, pas de système de plugins/hooks, pas de couche d'abstraction sans second cas d'usage réel). Si une pièce du socle technique commence à ressembler à Symfony/Laravel en miniature, c'est un signal pour s'arrêter et simplifier plutôt que continuer.
TDD obligatoire, méthode de travail non négociable (hérité de .claude/tdd.md, cf. §9) : RED → GREEN → REFACTOR sur toute classe portant de la logique, jamais de code écrit avant le test qui le justifie. Détaillé étape par étape en §7 (quel test RED avant quelle classe), mais c'est un principe transverse au même titre que l'async (§0.3) — pas une case à cocher en fin de développement.
Auth : compte personnel par musicien, appartenance à plusieurs groupes, rôle admin distinct, password_hash/session native PHP.
Expérience utilisateur 2026 : tout ce qui modifie des données passe en async (fetch/XHR), jamais de rechargement de page pour une action utilisateur. C'est un principe transverse, pas une feature isolée — voir §5bis ci-dessous, qui prime sur toute description antérieure d'un formulaire "classique".
0. Décisions d'architecture tranchées
0.1 Rôle utilisateur : colonne ENUM, pas de table roles
users.role ENUM('admin','musicien'). Justification : 2 rôles fixes, pas de besoin de permissions granulaires exprimé. Une table roles/user_role serait de la sur-ingénierie. Chemin d'extension documenté en commentaire de migration : si un 3e rôle apparaît, migrer vers roles + pivot user_role sans casser l'API (User::hasRole(string $role): bool reste l'interface stable côté code).

0.2 Concurrence sur les créneaux libérés : une seule table slot_exceptions, pas de slot_claims séparée
Une exception ponctuelle représente le cycle de vie complet d'une occurrence donnée (recurring_slot_id + date) : liberee → (revendiquee | expire naturellement le jour J sans revendication). La contrainte UNIQUE(recurring_slot_id, occurrence_date) empêche déjà la création de deux lignes pour la même occurrence. La revendication concurrente est gérée par un UPDATE conditionnel atomique :

UPDATE slot_exceptions
SET status = 'revendiquee', claimed_by_group_id = :group_id, claimed_by_user_id = :user_id, claimed_at = NOW()
WHERE id = :id AND status = 'liberee';
Si rowCount() === 0, la revendication a échoué (déjà prise par un autre groupe entre l'affichage et le clic) → on renvoie une erreur 409 propre en JSON, pas de verrou applicatif (GET_LOCK, mutex fichier, etc.), tout repose sur l'atomicité native de MySQL/InnoDB pour cette ligne. Fait dans une transaction courte (démarrée juste avant l'UPDATE, commit juste après).

Une table séparée slot_claims n'apporterait de valeur que pour un historique multi-tentatives — documenté comme extension future optionnelle, non construite au premier jet.

0.3 Modèle d'interaction : MPA rendue serveur + XHR systématique pour toute écriture
Navigation entre pages (login → dashboard → groupe → admin) reste du rendu serveur classique (chaque route PHP retourne une page HTML complète, coût de dev minimal, bon référencement/robustesse, cohérent avec "rester simple"). Mais aucune action qui modifie des données ne doit passer par un submit de formulaire natif avec rechargement de page : login, logout, claim/libération de créneau, tout le CRUD admin (créneaux, groupes, membres) — tout passe par fetch() vers des endpoints JSON, avec mise à jour du DOM en place côté client. Voir §5bis pour le détail par écran.

1. Arborescence du projet
RehearsalBox/
├── public/                          # UNIQUE document root exposée par le vhost/mutualisé
│   ├── index.php                    # Front controller unique
│   ├── .htaccess                    # Réécriture -> index.php (Apache mutualisé)
│   └── assets/
│       ├── css/
│       │   ├── base.css             # reset, variables CSS (couleurs, typo), mobile-first
│       │   ├── layout.css           # grille/flex, header, nav mobile (bottom bar)
│       │   ├── components.css       # cartes créneau, boutons, badges statut, toasts
│       │   └── pages/
│       │       ├── dashboard.css
│       │       ├── admin.css
│       │       └── auth.css
│       └── js/
│           ├── app.js                 # bootstrap JS (délégation d'événements globale)
│           ├── api.js                 # wrapper fetch() unique (headers, JSON, gestion erreurs, CSRF)
│           ├── forms.js                # helper générique: intercepte submit -> api.js -> callback succès/erreur
│           ├── toast.js                # notifications non bloquantes (succès/erreur) sans alert()
│           ├── auth.js                 # login/register/logout en XHR
│           ├── availability.js         # dashboard dispo (claim/libération en AJAX, DOM patché)
│           └── admin-slots.js           # CRUD créneaux + groupes en AJAX (create/update/delete sans reload)
│
├── src/
│   ├── Http/
│   │   ├── Request.php               # value object, construit depuis superglobales UNE FOIS (dans index.php)
│   │   ├── Response.php              # statut + headers + body, ->send()
│   │   ├── JsonResponse.php           # extends Response, encode JSON + content-type — réponse par défaut de TOUTE action d'écriture
│   │   └── RedirectResponse.php       # réservé aux transitions de page (ex: après login réussi en XHR, le JS redirige lui-même via window.location, ce n'est plus le serveur qui renvoie un 302 sur une action de formulaire)
│   │
│   ├── Routing/
│   │   ├── Router.php                 # enregistre routes, résout Request -> handler
│   │   ├── Route.php                  # value object: méthode, pattern, handler, nom
│   │   ├── RouteCollection.php
│   │   └── Exception/
│   │       ├── RouteNotFoundException.php
│   │       └── MethodNotAllowedException.php
│   │
│   ├── Container/
│   │   ├── Container.php              # DI container maison (voir §4)
│   │   ├── ContainerInterface.php
│   │   └── ServiceDefinition.php
│   │
│   ├── Controller/
│   │   ├── AbstractController.php     # helpers: render(), json(), redirect(), currentUser()
│   │   ├── PageController.php         # rend les pages HTML de navigation (dashboard, group, admin shells) — GET uniquement
│   │   ├── Api/
│   │   │   ├── AuthApiController.php      # POST login/register/logout -> JSON
│   │   │   ├── AvailabilityApiController.php  # POST claim/liberate -> JSON
│   │   │   ├── SlotApiController.php          # CRUD créneaux récurrents -> JSON
│   │   │   └── GroupApiController.php         # CRUD groupes + membres -> JSON
│   │   └── ErrorController.php        # 404/403/500 (pages HTML, pas d'API ici)
│   │
│   ├── Service/
│   │   ├── Contract/
│   │   │   ├── AuthServiceInterface.php
│   │   │   ├── AvailabilityServiceInterface.php
│   │   │   ├── SlotServiceInterface.php
│   │   │   └── GroupServiceInterface.php
│   │   ├── AuthService.php
│   │   ├── AvailabilityService.php    # logique métier libération/revendication
│   │   ├── SlotService.php            # CRUD créneaux récurrents (admin)
│   │   └── GroupService.php
│   │
│   ├── Repository/
│   │   ├── Contract/
│   │   │   ├── UserRepositoryInterface.php
│   │   │   ├── GroupRepositoryInterface.php
│   │   │   ├── RecurringSlotRepositoryInterface.php
│   │   │   └── SlotExceptionRepositoryInterface.php
│   │   ├── MysqlUserRepository.php
│   │   ├── MysqlGroupRepository.php
│   │   ├── MysqlRecurringSlotRepository.php
│   │   └── MysqlSlotExceptionRepository.php
│   │
│   ├── Entity/
│   │   ├── User.php
│   │   ├── Group.php
│   │   ├── RecurringSlot.php
│   │   ├── SlotException.php
│   │   └── Enum/
│   │       ├── UserRole.php            # enum PHP 8.1+ backed string
│   │       ├── Weekday.php             # enum 0..6
│   │       └── SlotExceptionStatus.php # enum liberee|revendiquee|expiree|annulee
│   │
│   ├── Security/
│   │   ├── PasswordHasherInterface.php
│   │   ├── NativePasswordHasher.php    # password_hash/password_verify
│   │   ├── SessionInterface.php
│   │   ├── NativeSession.php           # wrap session_start/$_SESSION
│   │   ├── CsrfTokenManager.php        # génère/valide un token CSRF, requis pour toute requête XHR mutante
│   │   └── AuthGuard.php               # require login / require role
│   │
│   ├── View/
│   │   ├── TemplateRendererInterface.php
│   │   └── PhpTemplateRenderer.php     # include PHP natif + ob_start, échappement via helpers
│   │
│   ├── Database/
│   │   ├── ConnectionFactory.php       # construit PDO depuis config, DSN, options
│   │   └── TransactionRunner.php       # helper: beginTransaction/commit/rollBack + callback
│   │
│   └── Kernel.php                       # assemble container, router, dispatch, gère exceptions -> 404/500 (JSON si requête API, HTML sinon)
│
├── templates/                          # vues PHP natives, uniquement pour les pages de NAVIGATION (GET)
│   ├── layout/
│   │   ├── base.php                    # inclut api.js, forms.js, toast.js partout
│   │   └── nav.php                     # bottom nav mobile
│   ├── auth/
│   │   ├── login.php                   # formulaire dont le submit est intercepté en JS (voir §5bis)
│   │   └── register.php
│   ├── dashboard/
│   │   ├── index.php                   # shell + squelette; le contenu dispo peut être rendu server-side au 1er chargement puis rafraîchi en JS
│   │   └── _slot-card.php              # partial PHP réutilisé côté serveur, doublé d'un template JS équivalent côté client (voir note §5bis)
│   ├── group/
│   │   └── show.php
│   ├── admin/
│   │   ├── slots/index.php             # shell; lignes gérées en JS après chargement initial
│   │   └── groups/index.php
│   └── error/
│       ├── 404.php
│       ├── 403.php
│       └── 500.php
│
├── config/
│   ├── config.php
│   ├── config.local.php.dist
│   ├── routes.php                       # déclare séparément routes "page" (HTML) et routes "api" (JSON)
│   └── services.php
│
├── database/
│   ├── schema.sql
│   ├── seed.sql
│   └── migrations/
│       ├── 001_create_users_table.sql
│       ├── 002_create_groups_table.sql
│       ├── 003_create_group_user_table.sql
│       ├── 004_create_recurring_slots_table.sql
│       └── 005_create_slot_exceptions_table.sql
│
├── bin/
│   └── migrate.php
│
├── vendor/
├── composer.json
├── .gitignore
└── README.md
Note mutualisé : public/ est la seule racine web exposée ; tout le reste est hors webroot.

2. Schéma de base de données (MySQL/MariaDB, InnoDB, utf8mb4)
-- database/schema.sql

CREATE TABLE users (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email                   VARCHAR(190) NOT NULL,
    password_hash           VARCHAR(255) NOT NULL,
    display_name            VARCHAR(100) NOT NULL,
    role                    ENUM('admin', 'musicien') NOT NULL DEFAULT 'musicien',
    is_active               TINYINT(1) NOT NULL DEFAULT 1,
    failed_login_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- §10.4 : freine le brute-force
    locked_until            DATETIME NULL,                        -- §10.4 : verrouillage temporaire après N échecs
    last_login_at           DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `groups` (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    genre           VARCHAR(60) NULL,
    color_hex       CHAR(7) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_user (
    group_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    CONSTRAINT fk_group_user_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_user_user  FOREIGN KEY (user_id)  REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring_slots (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id        INT UNSIGNED NOT NULL,
    weekday         TINYINT UNSIGNED NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_recurring_slots_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT chk_recurring_slots_time CHECK (start_time < end_time),
    KEY idx_recurring_slots_weekday (weekday, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slot_exceptions (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recurring_slot_id     INT UNSIGNED NOT NULL,
    occurrence_date       DATE NOT NULL,
    status                ENUM('liberee', 'revendiquee', 'expiree', 'annulee') NOT NULL DEFAULT 'liberee',
    released_by_user_id   INT UNSIGNED NOT NULL,
    released_reason       VARCHAR(255) NULL,
    claimed_by_group_id   INT UNSIGNED NULL,
    claimed_by_user_id    INT UNSIGNED NULL,
    claimed_at            DATETIME NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slot_exceptions_slot_date (recurring_slot_id, occurrence_date),
    CONSTRAINT fk_slot_exceptions_slot          FOREIGN KEY (recurring_slot_id)  REFERENCES recurring_slots(id) ON DELETE CASCADE,
    CONSTRAINT fk_slot_exceptions_released_by   FOREIGN KEY (released_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_slot_exceptions_claimed_group FOREIGN KEY (claimed_by_group_id) REFERENCES `groups`(id),
    CONSTRAINT fk_slot_exceptions_claimed_user  FOREIGN KEY (claimed_by_user_id)  REFERENCES users(id),
    KEY idx_slot_exceptions_date_status (occurrence_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE migrations_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration    VARCHAR(190) NOT NULL,
    applied_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_migrations_log_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
Point clé concurrence : la revendication passe TOUJOURS par UPDATE slot_exceptions SET status='revendiquee', ... WHERE id=? AND status='liberee' dans une transaction, jamais par un SELECT puis UPDATE séparés sans revérification du statut.

CHECK constraint : nécessite MariaDB >= 10.2.1 / MySQL >= 8.0.16 pour être réellement appliquée — à vérifier sur l'hébergeur mutualisé cible ; sinon revalidée côté SlotService.

3. Design du routeur maison
config/routes.php distingue explicitement routes de page (rendent du HTML, GET only pour la navigation) et routes API (toujours JSON, utilisées par le JS pour toute écriture) :

return [
    'pages' => [
        ['GET', '/',                [PageController::class, 'dashboard']],
        ['GET', '/login',           [PageController::class, 'login']],
        ['GET', '/register',        [PageController::class, 'register']],
        ['GET', '/groups/{id}',     [PageController::class, 'group']],
        ['GET', '/admin/slots',     [PageController::class, 'adminSlots']],
        ['GET', '/admin/groups',    [PageController::class, 'adminGroups']],
    ],
    'api' => [
        ['POST',   '/api/auth/login',                    [Api\AuthApiController::class, 'login']],
        ['POST',   '/api/auth/register',                 [Api\AuthApiController::class, 'register']],
        ['POST',   '/api/auth/logout',                    [Api\AuthApiController::class, 'logout']],
        ['GET',    '/api/availability',                    [Api\AvailabilityApiController::class, 'weekView']],
        ['POST',   '/api/availability/{slotId}/liberate',  [Api\AvailabilityApiController::class, 'liberate']],
        ['POST',   '/api/availability/{exceptionId}/claim', [Api\AvailabilityApiController::class, 'claim']],
        ['GET',    '/api/admin/slots',                     [Api\SlotApiController::class, 'index']],
        ['POST',   '/api/admin/slots',                     [Api\SlotApiController::class, 'store']],
        ['PATCH',  '/api/admin/slots/{id}',                 [Api\SlotApiController::class, 'update']],
        ['DELETE', '/api/admin/slots/{id}',                 [Api\SlotApiController::class, 'destroy']],
        ['GET',    '/api/admin/groups',                     [Api\GroupApiController::class, 'index']],
        ['POST',   '/api/admin/groups',                     [Api\GroupApiController::class, 'store']],
        ['POST',   '/api/admin/groups/{id}/members',        [Api\GroupApiController::class, 'addMember']],
        ['DELETE', '/api/admin/groups/{id}/members/{userId}', [Api\GroupApiController::class, 'removeMember']],
    ],
];
Toutes les méthodes HTTP (y compris PATCH/DELETE) sont utilisables nativement ici car ce sont des appels fetch() en JS, pas des formulaires HTML natifs — plus besoin du contournement _method que nécessiterait un submit natif.

Route.php compile le pattern {param} en regex nommée à l'enregistrement. Kernel::handle() route vers les pages (rend du HTML via TemplateRendererInterface) ou vers l'API (rend systématiquement du JsonResponse, y compris pour les erreurs — Kernel distingue le type de route en amont pour choisir le format d'erreur adapté : page HTML d'erreur pour /groups/{id} inexistant, JSON {error: ...} pour /api/*).

4. Conteneur DI minimal / Factory
Container à définitions explicites, résolu paresseusement (lazy singleton) — pas d'auto-wiring magique.

interface ContainerInterface
{
    public function set(string $id, callable $factory): void;
    public function get(string $id): mixed;
}
config/services.php câble PDO, les repositories (via interfaces), les services métier, la sécurité (dont CsrfTokenManager), le renderer de vues, et TOUS les controllers (page + API) avec leurs dépendances au constructeur — le Kernel résout le controller via le container, jamais de new en dur dans le routing.

5. Interfaces clés et implémentations concrètes
Interface	Implémentation	Rôle
Repository\Contract\UserRepositoryInterface	MysqlUserRepository	accès users + jointure group_user
Repository\Contract\GroupRepositoryInterface	MysqlGroupRepository	accès groups + group_user
Repository\Contract\RecurringSlotRepositoryInterface	MysqlRecurringSlotRepository	accès recurring_slots
Repository\Contract\SlotExceptionRepositoryInterface (dont claim(int $id, int $groupId, int $userId): bool)	MysqlSlotExceptionRepository	porte le UPDATE ... WHERE status='liberee'
Service\Contract\AuthServiceInterface	AuthService	orchestre hash + session, ne touche jamais $_POST directement
Service\Contract\AvailabilityServiceInterface	AvailabilityService	logique dispo, encapsule la transaction via TransactionRunner
Service\Contract\SlotServiceInterface	SlotService	CRUD créneaux récurrents
Service\Contract\GroupServiceInterface	GroupService	gestion groupes/membres, réservé admin (vérifié par AuthGuard)
Security\PasswordHasherInterface	NativePasswordHasher	isole l'algo
Security\SessionInterface	NativeSession	isole $_SESSION
View\TemplateRendererInterface	PhpTemplateRenderer	rendu des pages de navigation uniquement
Container\ContainerInterface	Container	voir §4
AuthGuard reste une classe concrète (pas d'interface, pas de variabilité utile), invoquée en tête de méthode de controller (page ou API) : $this->authGuard->requireRole(UserRole::Admin). Une requête API refusée renvoie un JsonResponse 403, une page refusée redirige/rend une page 403 HTML.

CsrfTokenManager : toute requête POST/PATCH/DELETE vers /api/* doit porter un header X-CSRF-Token validé contre le token de session — nécessaire précisément parce que ces actions ne passent plus par des formulaires natifs (qui bénéficient d'autres protections implicites), le JS doit l'injecter automatiquement via api.js.

5bis. Détail de l'async par écran (principe transverse, prime sur toute mention antérieure de formulaire classique)
Principe général porté par assets/js/api.js : un unique wrapper apiFetch(url, options) qui fixe Content-Type: application/json, injecte le token CSRF, parse la réponse JSON, et rejette une Promise avec un objet d'erreur normalisé ({status, message, fields?}) en cas de statut >= 400 — tous les autres scripts JS passent par lui, jamais de fetch() brut dispersé.

assets/js/forms.js : helper générique qui intercepte le submit de tout <form data-async>, empêche le comportement natif (preventDefault), sérialise en JSON, appelle apiFetch, affiche les erreurs de validation par champ, déclenche un callback de succès (redirection JS via window.location.href, ou mise à jour DOM). Ça évite de dupliquer la logique d'interception dans chaque script métier.

Login/Register (auth.js) : formulaire data-async, submit → POST /api/auth/login → succès : window.location.href = '/' (une seule vraie navigation complète, volontaire ici car changement de contexte de session) ; erreur (401, validation) : message inline sans reload.
Logout : simple bouton, POST /api/auth/logout en JS, puis redirection JS vers /login.
Dashboard / disponibilités (availability.js) : chargement initial rendu server-side (SEO/perf/pas de flash de contenu vide), puis toute interaction (libérer un créneau, revendiquer un créneau libéré) déclenche un POST /api/availability/..., et le DOM est patché en place (retrait/ajout de la carte concernée, toast de confirmation) sans jamais recharger la page. Cas 409 (déjà pris) : toast d'erreur + re-fetch ciblé de l'état de cette carte pour resynchroniser sans reload complet.
Fiche groupe : lecture rendue server-side ; action "libérer mon créneau" en XHR identique au dashboard.
Admin créneaux/groupes (admin-slots.js) : le shell de page (admin/slots/index.php) rend un conteneur vide ou une première liste server-side, puis create/update/delete passent tous par l'API JSON et re-render uniquement la ligne/carte concernée (ajout d'un <li>, remplacement en place, suppression avec animation courte) — jamais de rechargement de la liste complète ni de la page.
Confirmation d'action destructive (suppression d'un créneau/groupe) : pas de confirm() natif bloquant du navigateur (mauvaise UX mobile) — une modale HTML/CSS légère maison, elle-même pilotée en JS, avant l'appel DELETE.
Ce que ça change concrètement par rapport à la version précédente du plan : RedirectResponse n'est plus utilisée pour les soumissions de formulaire (elle ne sert plus qu'aux vraies navigations serveur, ex: accès direct à une URL protégée sans session → redirect vers /login) ; les controllers Api/* ne rendent jamais de HTML ; les templates ne contiennent plus de <form method="post" action="..."> classiques mais des <form data-async data-endpoint="..."> interceptés par forms.js.

6. Écrans / pages (mobile-first)
/login, /register — formulaire data-async, plein écran mobile, pas de nav, feedback d'erreur inline sans reload.
/ (Dashboard) — créneaux libérés à venir triés par date, bouton "Je prends ce créneau" par carte en XHR ; toggle jour/semaine (peut lui-même recharger juste les données via GET /api/availability?... plutôt que la page) ; bandeau "Mon prochain créneau fixe".
/groups/{id} — membres, créneau(x) récurrent(s), historique. Bouton "Libérer mon créneau du [date]" en XHR, visible aux membres du groupe pour leurs propres créneaux.
/admin/groups — liste, création, ajout/retrait de membres, tout en XHR avec mise à jour de liste en place.
/admin/slots — créneaux récurrents fixes par jour de semaine, CRUD en XHR.
Écrans d'erreur 403/404/500 — pages HTML classiques (pas d'API ici, c'est une vraie navigation cassée).
Navigation mobile : bottom nav bar fixe (Dashboard / Mon groupe / Admin si rôle admin / Déconnexion).

Direction artistique : palette sombre (#121212/#1a1a1a), accents saturés (rouge #e63946 ou orange électrique, violet néon en accent secondaire), typographie display condensée pour les titres (self-hosted woff2, pas de CDN externe), typographie standard lisible pour le corps. CSS organisé en custom properties (--color-bg, --color-accent, --space-*, --radius-*) dans base.css. Toasts non bloquants pour tous les retours d'action async (succès/erreur), pas d'alert()/confirm() natifs.

7. Plan d'implémentation par étapes
Méthode transverse : TDD obligatoire (RED → GREEN → REFACTOR), pas seulement documenté dans .claude/tdd.md. Pour toute classe portant de la logique (Service/*, Repository/*, Security/*, Routing/Router), le test s'écrit et échoue en premier, avant l'implémentation — jamais l'inverse, et jamais de test ajouté après-coup "pour faire joli". Exceptions volontaires : les classes purement structurelles sans branche logique (Http/Request value object, Entity/* sans comportement, templates PHP de vue) n'ont pas besoin d'un cycle RED/GREEN par elles-mêmes, mais tout ce qui les consomme (un Service qui les manipule) est testé. La couverture attendue par feature reste celle de tdd.md (§9) : accès non autorisé, cas nominal, cas limites, rollback/erreur — avec une insistance particulière sur l'IDOR (§10.5) et la concurrence de claim() (§0.2), qui sont les deux zones où un bug serait silencieux sans test dédié.

Stack : PHPUnit pour tout src/* PHP (pas de symfony/test-pack/ApiTestCase, un ApiTestCase maison léger si besoin pour taper les endpoints Api/* en HTTP réel via le serveur built-in, ou des tests unitaires directs sur les controllers avec un Request construit à la main). Jest (ou une alternative plus légère type node --test vu qu'il n'y a pas de bundler) pour assets/js/*, en particulier api.js/forms.js qui portent la logique CSRF/erreur partagée par tout le front.

Étape 0 — Initialisation du dépôt git init, .gitignore (exclut au minimum config/config.local.php, var//logs éventuels ; statuer sur vendor/ selon la politique de déploiement retenue à l'étape 8), premier commit, git remote add origin https://github.com/ronan-develop/RehearsalBox.git, push initial sur une branche dédiée (jamais de commit direct sur main, cf. git-conventions.md en §9). Créer ensuite .github/CONVENTION_DE_COMMIT.md pour que la référence dans git-conventions.md/commands/git.md (§9) cesse d'être un lien mort. Poser aussi phpunit.xml/phpunit.dist.xml + composer.json (autoload PSR-4 App\ → src/, App\Tests\ → tests/) dès cette étape, pour que l'étape 1 puisse démarrer directement en RED.

Étape 1 — Fondations DB database/schema.sql, migrations unitaires, bin/migrate.php, database/seed.sql. bin/migrate.php est lui-même testé (RED : test qui vérifie qu'une migration non jouée apparaît dans migrations_log après exécution, sur une base de test dédiée réinitialisée à chaque run — pas de mock de PDO ici, une vraie DB de test, cohérent avec tdd.md/§8 qui écarte SQLite justement pour rester fidèle).

Étape 2 — Socle technique transverse Http/*, Routing/* (avec distinction pages/api), Container/*, Database/*, config/*, public/index.php. RED d'abord sur Router::match() (cas nominal, 404, 405, params {id} extraits) et sur Container::get() (résolution, cache singleton, service inconnu → exception) avant d'écrire Router.php/Container.php. Valider ensuite avec une route page bidon + une route API bidon retournant du JSON.

Étape 3 — Couche Repository/Entity Entités simples (pas de test dédié, cf. règle ci-dessus), interfaces puis implémentations Mysql* — chaque méthode de repository testée contre la DB de test réelle (pas de mock PDO, l'enjeu ici est justement la requête SQL elle-même) : cas trouvé/non trouvé, contrainte UNIQUE violée, et pour MysqlSlotExceptionRepository::claim() spécifiquement le test RED qui vérifie qu'un second appel sur une exception déjà revendiquee retourne bien false (rowCount=0) sans lever — c'est le test le plus important du projet (§0.2/§10.5).

Étape 4 — Auth (full XHR dès le départ) Security/* (dont CsrfTokenManager), AuthService, Api\AuthApiController, templates auth/login.php/register.php avec data-async, assets/js/auth.js + api.js + forms.js. RED sur AuthService : login valide, mot de passe incorrect (message générique anti-énumération, §10.4), compte verrouillé après N échecs, régénération d'ID de session au succès. RED sur CsrfTokenManager : token valide accepté, token absent/invalide rejeté avant toute logique métier. Tests Jest sur forms.js (interception submit, sérialisation JSON) et api.js (injection du header CSRF, rejet de Promise normalisé sur 4xx/5xx). Valider ensuite manuellement : login/register/logout sans aucun reload de page visible pendant la soumission (seule la redirection finale post-login recharge).

Étape 5 — Écrans de consultation dispo (cœur métier) AvailabilityService + claim() transactionnel, Api\AvailabilityApiController, PageController::dashboard (rendu initial server-side), availability.js (fetch, patch DOM, gestion 409 avec toast). RED sur AvailabilityService::claim() : un musicien qui n'appartient pas au groupe revendicateur est rejeté (IDOR, §10.5) même si le payload contient un groupId valide d'un autre groupe — ce test doit exister et échouer avant que la vérification d'appartenance soit codée. RED aussi sur le double-claim concurrent (déjà couvert au niveau repository en étape 3, ici on teste que le service traduit bien false en réponse 409 JSON). Valider ensuite avec 2 sessions navigateur concurrentes sur le même créneau libéré → un seul claim aboutit, l'autre voit un toast d'erreur clair sans reload ni crash.

Étape 6 — Écrans admin (full XHR) SlotService, GroupService, Api\SlotApiController, Api\GroupApiController, shells de page admin, admin-slots.js. RED sur le rejet 403 d'un compte musicien appelant un endpoint admin directement (pas seulement testé manuellement en §8, un test automatisé fige ce comportement contre toute régression future). Restreint par AuthGuard::requireRole(UserRole::Admin).

Étape 7 — Finitions front mobile-first Polish CSS, toasts, modale de confirmation maison, test responsive réel. Pas de nouveau cycle TDD ici (CSS/disposition visuelle, hors périmètre du test automatisé) — vérifier manuellement qu'aucune action de la liste §5bis ne provoque de reload de page.

Étape 8 — Déploiement mutualisé composer install --no-dev --optimize-autoloader, committer vendor/, .htaccess de réécriture, test upload FTP à blanc. La suite PHPUnit/Jest tourne en local avant chaque déploiement (pas de CI automatique pour l'instant, cf. cicd.md en §9) — un déploiement ne part jamais avec des tests rouges.

8. Vérification / test manuel en local
Environnement dev : php -S localhost:8000 -t public public/index.php.
DB locale : MySQL/MariaDB local (pas SQLite — la sémantique exacte de rowCount() sur UPDATE ... WHERE et les ENUM/CHECK diffèrent trop de SQLite pour valider fidèlement la logique anti-concurrence).
Config locale : config/config.local.php.dist → config/config.local.php (gitignore).
Scénarios manuels à valider :
Inscription + connexion + déconnexion — vérifier dans les DevTools (onglet Network) qu'aucune de ces actions ne déclenche une navigation document complète, seulement des requêtes fetch XHR (sauf la redirection finale volontaire post-login).
Création d'un groupe et de son créneau récurrent (admin) — vérifier que la liste se met à jour sans reload.
Un membre du groupe titulaire libère son créneau pour une date précise → apparaît dans le dashboard des autres groupes sans qu'ils aient à rafraîchir manuellement (au minimum au prochain chargement du dashboard ; le temps réel multi-utilisateur type WebSocket n'est pas dans le périmètre initial, à documenter comme extension future si souhaité).
Un autre groupe revendique ce créneau → DOM patché en place, disparition de la liste des dispo, toast de confirmation.
Test de concurrence à 2 navigateurs sur le même claim → un seul aboutit, 409 propre côté perdant, affiché en toast, sans exception PHP visible.
Accès à /admin/* avec un compte musicien → 403 (page HTML si accès direct à l'URL, JSON si appel API direct).
Suppression d'un créneau/groupe → modale de confirmation maison (pas de confirm() natif), suppression en XHR, ligne retirée du DOM avec transition.
Mobile-first : DevTools responsive (360/390/414px) puis test sur vrai téléphone (Android + iOS si possible) — zones tactiles des boutons d'action async, lisibilité sur fond sombre, comportement des toasts avec le clavier virtuel ouvert.
Logs erreurs : debug => false committé par défaut en config de prod ; debug => true seulement dans config.local.php non committée.
9. Migration du dossier .claude/ (venant du projet HomeCloud)
Le dossier .claude/ présent dans RehearsalBox a été copié tel quel depuis un autre projet ("HomeCloud", Symfony + Doctrine + API Platform, hébergé sur o2switch). Il faut l'adapter : réutiliser ce qui est générique (conventions git, méthodologie TDD, principes de design CSS), réécrire ce qui dépend de la stack Symfony (inapplicable en PHP pur), et supprimer ce qui est strictement métier à l'autre projet (pipeline médias/RAW).

Dépôt GitHub cible : https://github.com/ronan-develop/RehearsalBox.git — le remote existe désormais, mais le répertoire local n'est pas encore un dépôt git (git status renvoie "not a git repository"). Avant toute autre étape d'implémentation, il faudra : git init, créer .gitignore (cf. §1 : exclure config/config.local.php, vendor/ si non committé — décision à trancher à l'étape 8 du §7 selon la politique retenue pour le mutualisé), premier commit, git remote add origin https://github.com/ronan-develop/RehearsalBox.git, puis push initial sur une branche (jamais directement de commit sur main, cf. §git-conventions). Une fois ce dépôt initialisé, créer .github/CONVENTION_DE_COMMIT.md (référencé par git-conventions.md, cf. tableau ci-dessous) — il n'existe pas encore, les fichiers adaptés le mentionneront comme "à créer" jusqu'à ce moment-là.

Verdict par fichier
Fichier	Action
architecture.md	Réécrire — remplacer les sections Doctrine/UUID v7/API Platform/pipeline média par l'arborescence src/ réelle de RehearsalBox (§1 de ce plan) et les principes SOLID/DIP/SRP déjà déclinés en §0/§5. Garder l'esprit du tableau "génération de fichiers" mais adapté (pas de make:migration, on a bin/migrate.php).
deploiement.md	Réécrire en squelette WIP explicite — garder la structure générale (méthode de déploiement, prérequis hébergeur, variables d'environnement, diagnostic) mais vider tout le contenu o2switch-spécifique (sous-domaines .lenouvel.me, Messenger worker, JWT). Ajouter un bandeau "hébergeur non choisi — section à compléter une fois l'hébergeur connu". Si l'hébergeur choisi est finalement o2switch aussi, le futur toi pourra réinjecter des morceaux (méthode SSH, whitelist IP) depuis l'ancien fichier — mais ne pas le présumer.
frontend.md	Adapter — garder le principe (variables CSS custom properties, dark mode via prefers-color-scheme, règles anti-glassmorphism, transitions systématiques) mais renommer le préfixe --hc-* en --rb-* (ou autre préfixe court pour RehearsalBox) et remplacer la palette par celle définie en §6 (fond sombre #121212, accents rouge/orange/violet néon, typographie display condensée).
medias.md	Supprimer — 100% pipeline photo RAW/EXIF/vignettes, aucune notion de média dans RehearsalBox.
layout.md	Adapter — garder la logique (bottom nav/tab-bar fixe mobile, breakpoints, règles overflow pour confiner le scroll) en la rescopant sur la bottom nav définie en §6 (Dashboard / Mon groupe / Admin / Déconnexion), renommer les classes .hc-*.
git-conventions.md	Garder quasi tel quel — convention de commit emoji+type+scope, workflow issue→branche→PR, limites d'autonomie sont génériques. Juste retirer la référence à un .github/CONVENTION_DE_COMMIT.md existant (le noter comme fichier à créer).
cicd.md	Réécrire, très réduit — remplacer PHPUnit+MariaDB/Jest (ok, transposable tel quel) mais supprimer tout le détail o2switch/webhook cassé/Messenger. Section déploiement renvoie vers deploiement.md marqué WIP.
tdd.md	Garder le cycle RED/GREEN/REFACTOR et la couverture attendue (accès non autorisé, nominal, cas limites, rollback) — juste remplacer la stack (symfony/test-pack, ApiTestCase) par PHPUnit nu + un futur AuthenticatedTestCase maison si besoin pour les endpoints /api/*.
commands.md	Réécrire entièrement — remplacer les commandes Symfony (bin/console, Doctrine, JWT) par les commandes réelles du projet : php -S localhost:8000 -t public, php bin/migrate.php, ./vendor/bin/phpunit.
commands/git.md	Garder tel quel (même contenu que git-conventions.md, générique).
templates/entity.stub	Réécrire — entité PHP pur simple (constructeur avec paramètres métier, pas d'UUID Doctrine, pas d'annotations ORM), cohérente avec src/Entity/*.php du plan (§1).
templates/test-api.stub	Réécrire — remplacer AuthenticatedApiTestCase/Doctrine par un gabarit de test PHPUnit ciblant nos Api\*Controller (test d'un endpoint JSON avec session simulée), aligné sur les scénarios de vérification du §8. Ce stub doit rester utilisable en RED (créé et lancé avant d'écrire le controller qu'il teste) — c'est un outil du cycle TDD (§7), pas juste un gabarit de complétude après coup.
settings.json	Réduire — remplacer les permissions de commandes Symfony par nos équivalents (php bin/migrate.php, ./vendor/bin/phpunit, git/gh déjà génériques).
settings.local.json	Ne pas récupérer — 100% chemins SSH/tests spécifiques à home-cloud. Repartir d'un fichier vide ou minimal.
CLAUDE.md (racine du repo, hors .claude/)	Réécrire entièrement — c'est l'index chargé en premier, il pointe aujourd'hui vers les mêmes fichiers Symfony/Doctrine/Tailwind (UUID Doctrine, entités User/Folder/File/Media, grille Tailwind, bin/console). À reconstruire sur le même format (index + règles critiques toujours actives) mais avec les vrais liens vers les fichiers .claude/*.md adaptés, la vraie stack (§0 : PHP pur/PDO/JS vanilla), et sans la section "Entités Doctrine"/"UUID Doctrine" (remplacée par un rappel du garde-fou anti-usine-à-gaz de l'introduction). Section "Règles critiques" à garder quasi telle quelle (secrets, git, TDD) puisqu'elle est déjà générique.
CI GitHub Actions — à créer, absente du .claude/ copié, dès le commit initial
Le .claude/ d'origine documente une CI (cicd.md : job PHPUnit+MariaDB, job Jest) mais aucun fichier .github/workflows/*.yml n'a été copié — seule la documentation qui en parle. Il faut créer le workflow réel, pas seulement adapter un texte.

Décision actée : le ci.yml complet est écrit et poussé dès le tout premier commit (étape 0), pas en version "factice" temporaire. Le repo est vide à ce moment (pas de composer.json, pas de tests) — le job échouera donc (rouge) jusqu'à ce que l'étape 1 apporte composer.json/PHPUnit. C'est un choix assumé plutôt qu'un problème à masquer : un badge rouge de démarrage est honnête (il n'y a réellement rien à tester encore), préférable à une CI simplifiée qu'il faudrait ensuite remplacer par la vraie. Contenu du workflow dès le départ : job PHP (setup PHP 8.4, service MariaDB, composer install, composer audit cf. §10.8, ./vendor/bin/phpunit) et job JS (setup Node, npm test sur les tests Jest de assets/js/*) — les deux jobs existent tels quels dès l'étape 0, ils se mettent simplement à passer au vert au fur et à mesure que le code et les tests arrivent (étape 1 pour PHPUnit, étape 4 pour les premiers tests Jest sur api.js/forms.js).

Point de mécanique important : un fichier .github/workflows/*.yml ne déclenche réellement un run que depuis un dépôt GitHub existant avec des events (push/PR) à observer — donc ci.yml doit être committé après git remote add origin ... et le push initial (fin de l'étape 0), pas avant. À intégrer dans cicd.md une fois écrit, en remplaçant la mention actuelle "PHPUnit + MariaDB / Jest" par un lien direct vers ce fichier réel.

Séquencement
Cette migration du .claude/ est indépendante du plan d'implémentation de l'app (§7) — elle peut être faite en premier, en parallèle, ou juste après l'étape 1, sans bloquer aucune étape. Ordre suggéré : git-conventions.md/commands/git.md (aucun changement de fond) → tdd.md → architecture.md (dépend de l'arborescence §1, donc à faire une fois celle-ci stabilisée) → frontend.md/layout.md (dépendent de la palette/layout §6) → CLAUDE.md racine (dépend que tous les fichiers .claude/*.md qu'il référence soient déjà réécrits, donc en avant-dernier) → commands.md/settings.json (dépendent des vraies commandes une fois le socle technique posé, étape 2 du §7) → cicd.md/deploiement.md/.github/workflows/ci.yml en dernier (dépendent de l'étape 0 — dépôt git initialisé — et de l'étape 1 — composer.json/PHPUnit en place), deploiement.md restant WIP tant que l'hébergeur n'est pas choisi → templates/*.stub en dernier (dépendent des entités réelles, étape 3 du §7).

10. Plan de sécurité
Sans ORM, aucune couche n'échappe le SQL "gratuitement" à ta place — chaque accès aux données passe par un repository écrit à la main, donc chaque requête est un point de vigilance individuel. De même, le passage en XHR systématique (§0.3) élargit la surface CSRF/validation par rapport à des formulaires natifs classiques. Cette section reprend les mesures déjà esquissées ailleurs dans le plan (CSRF en §5, prepared statements implicites en §2) et comble ce qui manquait.

10.1 Injection SQL
Règle absolue : 100% requêtes préparées (PDO prepared statements), jamais de concaténation de valeur utilisateur dans une chaîne SQL. Y compris pour des valeurs qui semblent "sûres" (ID numérique casté, enum) — le cast/la validation se fait de toute façon en amont côté PHP ((int) $id, UserRole::from($value) qui lève si invalide), mais la requête reste paramétrée par principe de défense en profondeur.
PDO en mode exceptions : PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION dans ConnectionFactory — une requête malformée doit lever, jamais échouer silencieusement ou retourner un état incohérent exploitable.
Émulation de prepare désactivée : PDO::ATTR_EMULATE_PREPARES => false — force MySQL à traiter réellement les paramètres comme des données typées côté serveur plutôt que MySQL substitués côté client par PDO (protection plus robuste, en particulier utile si le charset de connexion venait à être mal négocié).
Identifiants de colonnes/tables jamais interpolés depuis une entrée utilisateur : les noms de colonnes pour un éventuel tri dynamique (ORDER BY) doivent passer par une whitelist statique côté PHP (match($sortField) { 'date' => 'occurrence_date', ... }), jamais un nom de colonne construit directement depuis $_GET.
Repositories = seule couche autorisée à toucher le SQL — un service ne construit jamais de fragment de requête, il appelle une méthode de repository avec des arguments typés. Ça borne le risque d'injection à une poignée de fichiers Mysql*Repository.php, faciles à auditer un par un.
10.2 XSS (Cross-Site Scripting)
Échappement systématique à l'affichage, jamais à la saisie : toute donnée injectée dans un template PHP passe par un helper e(string $value): string (wrapper htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) déjà mentionné en §5 pour PhpTemplateRenderer — à rendre non contournable par convention (jamais de <?= $value ?> brut dans les templates, toujours <?= e($value) ?>).
Danger spécifique au XHR généralisé (§0.3) : le JS injecte souvent du contenu serveur dans le DOM via innerHTML (ex: admin-slots.js qui ajoute une ligne après un POST, availability.js qui patche une carte). Règle : ne jamais faire element.innerHTML = data.displayName avec une valeur venant de l'API. Soit utiliser textContent/el.setAttribute pour les valeurs dynamiques et ne réserver innerHTML qu'à des fragments HTML statiques contrôlés par le code, soit passer par une fonction d'échappement JS équivalente (escapeHtml() dans api.js) si un template JS composite est nécessaire (cf. _slot-card.php doublé en JS, §1) — à documenter explicitement dans assets/js/api.js comme convention d'équipe (même seul, ça structure la discipline).
Content-Security-Policy : header à poser dans Kernel sur toute réponse HTML (pas nécessaire sur les réponses JSON) — au minimum default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; base-uri 'self'; form-action 'self' — cohérent avec "pas de CDN externe" déjà acté pour les fonts (§6) et empêche l'exécution de JS injecté même en cas d'oubli d'échappement quelque part.
Cookies de session : httponly (déjà en §5 implicitement via "session native"), à rendre explicite ici — sans httponly, un XSS réussi pourrait exfiltrer le cookie de session directement.
10.3 CSRF
Déjà couvert en §5 (CsrfTokenManager, header X-CSRF-Token obligatoire sur tout POST/PATCH/DELETE vers /api/*) — point de vigilance additionnel : le token doit être vérifié côté serveur AVANT toute autre logique métier (avant même AuthGuard::requireRole), dans le Kernel ou un point d'entrée commun à tous les controllers Api/*, pas dans chaque controller individuellement (sinon un oubli sur un seul endpoint casse la protection globale — DRY appliqué à la sécurité, pas juste au code).
SameSite=Strict ou Lax sur le cookie de session (déjà en §5) : défense complémentaire au token CSRF, pas un remplacement — les deux mesures sont indépendantes et se cumulent (SameSite protège même si un endroit oublie de vérifier le token, le token protège même sur un navigateur ancien qui ignore SameSite).
Le token CSRF est lié à la session (régénéré à chaque login, cf. SessionInterface::regenerate() déjà listé en §5) — pas un token statique généré une fois pour l'app entière.
10.4 Authentification et gestion de session
password_hash() avec PASSWORD_DEFAULT (déjà acté en §5/§0) — jamais MD5/SHA1, jamais de sel manuel (géré par l'algo).
Limitation du taux de tentatives de login : un compteur simple (colonne failed_login_attempts + locked_until sur users, ou une table login_attempts par IP/email) pour freiner le brute-force — absent du schéma §2 actuel, à ajouter. Pas besoin de service tiers (captcha, etc.) pour un nombre d'utilisateurs aussi restreint, mais un verrouillage temporaire après N échecs (ex: 5 tentatives → 15 min de blocage) est peu coûteux et couvre l'essentiel du risque.
session_regenerate_id(true) après un login réussi — empêche la fixation de session (un attaquant qui aurait fixé un ID de session avant l'authentification ne peut pas réutiliser cet ID après).
Timeout de session : expiration après une durée d'inactivité raisonnable (ex: 2 semaines, cohérent avec un usage occasionnel "on regarde vite fait avant la répète" et l'absence de remember-me déjà actée en §0) — géré via session.gc_maxlifetime + un timestamp last_activity en session, revalidé à chaque requête protégée par AuthGuard.
Énumération de comptes : le message d'erreur de login doit être générique ("identifiants invalides"), jamais distinguer "email inconnu" de "mot de passe incorrect" — évite de laisser un attaquant lister les emails valides du local.
10.5 Autorisation (IDOR / contrôle d'accès par ressource)
Risque spécifique à ce domaine : un musicien authentifié mais non membre d'un groupe ne doit jamais pouvoir agir sur les créneaux/données de ce groupe, même en devinant un ID (POST /api/availability/{exceptionId}/claim avec un ID d'une exception liée à un créneau d'un groupe dont l'utilisateur n'est pas membre — ou pire, tenter de "claim" au nom d'un groupe auquel il n'appartient pas en manipulant le payload groupId).
Règle : chaque service métier (AvailabilityService::claim, SlotService::update, GroupService::removeMember) vérifie l'appartenance/l'ownership côté serveur à partir de l'utilisateur en session, jamais à partir d'un groupId/userId fourni dans le payload de la requête. Concrètement, claim(int $exceptionId, int $groupId, int $userId) ne doit jamais faire confiance à un $groupId envoyé par le client sans vérifier que l'utilisateur courant (déduit de la session, pas du payload) appartient bien à ce groupe — sinon n'importe quel musicien connecté pourrait revendiquer un créneau "au nom" d'un groupe auquel il n'appartient pas.
Ce contrôle est une responsabilité de service (SRP), pas de repository ni de controller — cohérent avec GroupService/AvailabilityService déjà en §5, mais à expliciter comme test obligatoire (cf. tdd.md adapté en §9 : "accès non autorisé" est déjà dans la couverture attendue par feature — s'assurer que ça couvre spécifiquement l'IDOR inter-groupes, pas seulement le rôle admin/musicien).
10.6 Validation des entrées
Toute donnée reçue côté Api/*Controller est validée avant d'atteindre un service : types stricts (declare(strict_types=1) partout), longueurs de chaînes (cohérentes avec les colonnes VARCHAR du schéma §2 — ex: display_name ≤ 100, rejeter avant l'écriture plutôt que laisser MySQL tronquer silencieusement ou lever une erreur SQL brute), formats (email via filter_var(FILTER_VALIDATE_EMAIL), dates via DateTimeImmutable::createFromFormat avec vérification stricte du format plutôt que strtotime qui accepte des formats ambigus).
Erreurs de validation renvoyées en JSON structuré ({status: 422, fields: {email: "format invalide"}}) — jamais un message d'exception PHP brut exposé au client (cohérent avec debug => false en prod déjà acté en §8).
Upload : hors périmètre actuel (aucun écran ne prévoit d'upload de fichier dans le plan) — si une photo de groupe ou un avatar est ajouté plus tard, point de vigilance à traiter à ce moment-là (validation de type MIME réel via finfo, pas l'extension déclarée ; stockage hors webroot ou nom de fichier non devinable).
10.7 En-têtes HTTP et configuration serveur
En-têtes de sécurité systématiques (posés dans Kernel pour toute réponse) : X-Content-Type-Options: nosniff, X-Frame-Options: DENY (ou Content-Security-Policy: frame-ancestors 'none', équivalent moderne), Referrer-Policy: strict-origin-when-cross-origin.
HTTPS obligatoire en prod : redirection forcée HTTP→HTTPS (via .htaccess sur le mutualisé, cf. deploiement.md en §9, à inclure dans le squelette même WIP) + cookie de session marqué Secure.
.htaccess de public/ : interdiction explicite d'accès aux fichiers sensibles s'ils se retrouvaient par erreur dans le webroot (.git, .env-équivalent config.local.php, *.sql) — défense en profondeur en plus du fait que src//config//database/ sont déjà hors webroot par construction (§1).
Config prod vs dev : déjà acté en §8 (debug => false par défaut committé) — à renforcer ici : aucune information de version PHP/MySQL ne doit fuiter (expose_php = Off dans php.ini si modifiable sur le mutualisé, sinon accepté comme limite documentée).
10.8 Dépendances
composer audit avant chaque déploiement (déjà présent dans l'ancien cicd.md, réutilisable tel quel — cf. §9) — même avec peu de dépendances (pas de framework), toute lib ajoutée (ex: un futur helper CSV, une lib de dates) doit être auditée.
Committer composer.lock — garantit que les versions exactes testées sont celles déployées sur le mutualisé, pas une résolution différente au moment du composer install en prod.
10.9 Ce qui reste explicitement hors périmètre (à documenter, pas à ignorer)
Rate limiting global / anti-DDoS applicatif : hors de portée d'un hébergement mutualisé sans contrôle sur la couche réseau — au mieux le frein de tentatives de login (§10.4). À ne pas confondre avec une vraie protection anti-DDoS (relèverait d'un CDN/WAF, hors périmètre budget/complexité de ce projet).
2FA : explicitement écarté en §0 (décision déjà validée) — cohérent avec le profil de risque (petit groupe d'utilisateurs connus, pas de données financières).
Audit de sécurité externe / pentest : non prévu, proportionné à la taille du projet — mais rien dans ce plan n'empêche d'en faire un plus tard si l'app grossit (l'architecture en couches/interfaces facilite justement ce genre d'audit ciblé).
Répercussion sur le schéma DB (§2) et l'arborescence (§1)
database/schema.sql : colonnes failed_login_attempts et locked_until déjà intégrées sur users (§2, §10.4).
Ajout à src/Security/ : CsrfTokenManager.php déjà prévu (§1) — préciser qu'il expose aussi une méthode utilisée en tout premier dans Kernel::handle() pour les routes API mutantes, pas seulement dans les controllers.
Ajout à src/Http/ : les helpers d'en-têtes de sécurité (§10.7) trouvent naturellement leur place dans Kernel.php (déjà responsable de construire la Response finale, §1) plutôt qu'une nouvelle classe — évite d'ajouter une couche pour ça (cohérent avec le garde-fou anti-usine-à-gaz déjà acté en introduction).