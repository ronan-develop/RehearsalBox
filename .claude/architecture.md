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
| `symfony/mercure` | Non retenu | Optionnel, y compris avec API Platform (qui fonctionne sans, ex. un autre projet du dépôt voisin `home-cloud`). Le push temps réel demande un hub permanent, impossible sur mutualisé : on garde le polling. |
| `routing`, `http-foundation`, `http-kernel` | Écarté | Remplacer le socle minimal = réécrire tous les contrôleurs, pour un gain faible. |
| `API Platform` | À évaluer (https://github.com/ronan-develop/RehearsalBox/issues/172) | Utile surtout pour une API publique ou un client natif ; demande le framework Symfony complet. Spike borné avant toute décision. |

**Mises à jour** : `.github/dependabot.yml` propose les PR (composer + GitHub Actions) chaque semaine ; `composer audit` tourne en CI et avant chaque déploiement. Symfony 8.x est une branche à versions mineures courtes (passer à la mineure suivante à chaque sortie) ; la branche LTS reste une option si le rythme pèse.

## En-têtes de sécurité (#226)

Politique unique dans `App\Security\SecurityHeaders`, appliquée par le Kernel à **toute** réponse dynamique (pages, API, erreurs 4xx/5xx, redirections) : CSP stricte (`script-src 'self'`, aucun script inline, `style-src-attr 'unsafe-inline'` seulement pour les `style=` des couleurs de groupe, `frame-ancestors 'none'`, `object-src 'none'`, `base-uri` et `form-action` sur `'self'`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` restrictive, `Cross-Origin-Opener-Policy`, et `Cache-Control: private, no-store` par défaut. HSTS (30 jours, sans sous-domaines) seulement si `app.base_url` est en HTTPS.

- Un en-tête déjà posé par un contrôleur est **prioritaire** (`Response::withDefaultHeaders`, insensible à la casse) : les pages à jeton gardent `Referrer-Policy: no-referrer`, un téléchargement peut définir son propre cache.
- **Ne pas** poser de `Cache-Control`/`Referrer-Policy` « par habitude » dans un contrôleur : c'est le défaut.
- Aucun script inline, aucune ressource externe, aucun `on…=` : toute nouvelle dépendance externe doit d'abord élargir la CSP (et un test). Vérifier une nouvelle page dans le navigateur avec la console ouverte (violation = erreur `security`).
- Les assets servis directement par Apache (CSS, JS) reçoivent `nosniff` via `public/.htaccess`.
- JS : tout HTML construit côté client échappe ses données avec `escapeHtml` de `assets/js/html.js` (module unique).

## Connexion : verrou, limite par adresse, temps de réponse (#218)

- **Verrou par compte** : 5 échecs → 15 minutes. Le compteur est incrémenté **en SQL, en une seule requête** (`UserRepositoryInterface::recordFailedLogin`) : jamais « lire, calculer en PHP, réécrire la ligne » (des tentatives simultanées s'écraseraient et le verrou serait contournable). Le compteur est plafonné (colonne étroite). Remise à zéro atomique aussi (`resetFailedLogins`, utilisée par la connexion réussie et le déblocage administrateur).
- **Limite par adresse** (`LoginThrottle`, table `login_failures`) : 20 échecs en 15 minutes → 429 + `Retry-After`, **avant** de toucher à un compte (la limite ne verrouille jamais personne et ne dépend d'aucun compte). L'adresse (celle de la connexion TCP, jamais un en-tête falsifiable) est stockée en **empreinte SHA-256 propre à l'application**, jamais en clair, et purgée après 24 h. Adresse absente = personne n'est bloqué.
- **Temps de réponse homogène** : compte inconnu, inactif ou verrouillé → `PasswordHasherInterface::simulateVerification` (un hachage jeté coûte autant qu'une vérification). Un test compte les opérations de hachage de chaque branche.
- Risque résiduel connu : un attaquant qui change d'adresse peut encore verrouiller un compte précis 15 minutes (5 échecs) ; un administrateur peut le débloquer. Un compte n'est jamais verrouillé par la limite d'adresse.
- Après un déploiement, vérifier que deux clients différents sont bien vus avec des adresses différentes (sinon la limite par adresse bloquerait tout le monde).

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
- **Routes (serveur d'abord, #183)** : pages `/messages`, `/messages/archives`, `/messages/{id}` (une route par conversation ; l'ouvrir la marque lue) et `/messages/new/{groupId}` (conversation vide « à la Signal », rien n'est créé avant le premier message). **Chaque URL est une page rendue par PHP**, navigation classique : la lecture et la navigation marchent sans JS. API : `POST /api/conversations` (créer), `POST /api/conversations/{id}/messages` (envoyer), `PATCH /api/conversations/{id}` (titre), `POST …/typing`, `GET /api/conversations/{id}/updates?after=<id>` (polling) et `GET /api/conversation-list` (rafraîchir la liste) qui renvoient des **fragments HTML rendus par les mêmes gabarits que la page**, `GET /api/conversations` (totaux « non lu » du dashboard).
- **Quasi temps réel sans push** : polling (fil ouvert ≈ 4 s, ralenti jusqu'à 15 s sans activité ; liste ≈ 30 s ; arrêt onglet caché). « Vu par » = lecteurs dont `last_read_at` ≥ date de mon dernier message ; « écrit… » = signal limité à un toutes les 2 s (dépôt) valable 5 s, jamais pour soi-même.
- **Pastille** : initiales de l'auteur, couleur du groupe d'appartenance parmi les deux groupes de la conversation (neutre si ambigu) ; aucun changement de schéma, aucun identifiant interne exposé (`ConversationPresenter`).
- **Saisie** : `ConversationInputPolicy` (titre ≤ 150 caractères sans caractère de contrôle, message ≤ 5 000), limite de 30 messages par heure et par personne (429). Le texte n'est jamais inséré en HTML côté client (`textContent`).
- **Affichage** : `ConversationFormatter` (heures et jours dans le fuseau `app.timezone`, « écrit… », « Vu par », aperçus), `ConversationTimeline` (jours, séries, « non lus », pastille avec couleur validée `SafeColor`), `ConversationListView`, `MessagesPageView` ; gabarits `templates/messages/_rows.php` et `_conversation-items.php` (un seul endroit qui dessine, page **et** fragments, tout contenu d'utilisateur par `e()`). Le JS (`public/assets/js/chat/`) se limite au direct : polling, envoi, signal d'écriture, titre (voir `.claude/frontend.md`). Mise en page à la Signal (mobile : liste OU fil ; ≥ 900 px : deux colonnes).
- **Corbeille (#190)** : seule la personne qui a ouvert la conversation (`conversations.created_by`) peut la mettre à la corbeille (`deleted_at`), la restaurer ou la supprimer pour de bon ; tout autre reçoit le refus uniforme. À la corbeille, elle disparaît **immédiatement** des listes, compteurs, relances et de l'accès des deux groupes ; l'initiateur la retrouve dans `/messages/trash` pendant 30 jours (`ConversationTrashService::TRASH_RETENTION`), puis elle est purgée (au passage dans `trash()`, sans cron). Les autres participants reçoivent un **avis dans l'application** (`conversation_alerts` : type, noms des groupes, jamais le titre), affiché en haut de la liste, fermable, compté dans la pastille. API : `DELETE /api/conversations/{id}`, `POST …/restore`, `DELETE …/permanent`, `POST /api/conversation-alerts/{id}/dismiss`.
- **Mentions et invités (#178)** : taper `@` dans la saisie propose **tous les membres actifs** du site (`GET /api/members`, par nom, avec leur groupe : « Denis · Nebula Sprawl » ; jamais d'adresse ; 2 caractères minimum, 10 résultats, contexte obligatoire : conversation dont on est participant, ou groupes d'une nouvelle conversation). Le client envoie des **identifiants** (`mentions: [id]`), le serveur revérifie tout (`ConversationMentionService::plan`) : personne active, au plus 10, et le « @Nom » doit figurer dans le texte. Les mentions sont stockées par identifiant (`message_mentions`, avec le libellé inséré) ; l'affichage découpe le texte (`MentionText`) et échappe chaque segment. **Taguer quelqu'un hors des deux groupes l'ajoute comme invité de CETTE conversation seulement** (`conversation_guests`) : une ligne du fil l'annonce (« Alice a ajouté Denis »), l'interface prévient avant l'envoi, seuls les membres des deux groupes peuvent ajouter (un invité mentionne les participants), l'ajouteur, l'initiateur ou l'invité lui-même peuvent le retirer (`DELETE /api/conversations/{id}/guests/{userId}`). Un invité est un participant à part entière (liste, compteurs, « vu par », avis de corbeille) mais n'est ni membre d'un groupe ni destinataire des e-mails de groupe. La personne taguée voit sa bulle surlignée et un marqueur « @ » dans la liste tant qu'elle n'a pas lu.
- **E-mails de mention (#178, phase 2)** : une personne taguée reçoit un e-mail à l'adresse de **son compte** (jamais le titre ni le texte ; qui l'a mentionnée, un lien vers la conversation, un lien vers « Mon compte » pour se désinscrire). **Au plus un e-mail par conversation et par personne toutes les 24 h** (`conversation_mention_notices`, réservation atomique : dix tags en une heure = un e-mail), et **au plus 10 e-mails par auteur et par heure** (`MentionNotifier`). Ignorés : l'auteur lui-même, les personnes inactives, sans adresse valide ou **désinscrites** (`users.email_notifications`, case Oui/Non dans Mon compte, `PATCH /api/account/notifications`, dépôt `NotificationPreferenceRepository` séparé de l'entité `User`). Un échec d'envoi n'est jamais remonté à l'auteur du message et annule la réservation (réessayable). **Relance** : une seule, 24 h après l'e-mail de mention si la conversation n'a pas été lue depuis le message qui mentionne, dans la plage de jour (9 h–20 h, `DaytimeWindow`, partagée avec les relances de groupe), jamais au-delà de 7 jours (`MentionReminderService`, lancé par le même `bin/send-reminders.php`).
- **Éditer son message (#200)** : seul l'**auteur** corrige son message (même pas l'initiateur de la conversation ni un membre de son groupe), pendant **15 minutes** (`MessageEditService::EDIT_WINDOW`), jamais une ligne système ; tout autre cas = refus uniforme. Mêmes règles de saisie qu'à l'envoi, une correction compte dans la limite horaire (`ConversationRateLimit`, partagée avec l'envoi : messages + corrections). L'ancien texte est **conservé côté serveur** (`conversation_message_versions`, audit, jamais affiché) et `edited_at` est posé ; la bulle affiche « modifié à HH:MM » à la place de l'heure d'envoi. Les mentions suivent le texte (`planEdit` : conservées tant que « @Nom » y figure, retirées sinon, nouvelles validées comme à l'envoi) et une correction n'envoie **jamais** d'e-mail. **Propagation** : le polling `GET …/updates?after=<id>&editedAfter=<secondes Unix>` renvoie aussi les messages déjà reçus corrigés depuis le curseur (`edited: [{id, html}]` + `editedAt`) ; le corps de bulle est dessiné par le même gabarit PHP (`_message-body.php`, `EditedMessageFragments`) et substitué dans la page. API : `PATCH /api/conversations/{id}/messages/{messageId}` (`MessageApiController`). Interface : bouton au survol/clavier sur ordinateur, **glissement vers la gauche** sur sa bulle sur écran tactile (`swipe-edit.js`, même logique de geste que la suppression dans la liste ; pas d'appui long : Safari iOS le détourne vers la sélection de texte, #212), bandeau « Modification du message » avec annulation (Échap) ; la saisie en cours est mise de côté et rendue à la fin.
- **Services de la messagerie, une responsabilité chacun** : `ConversationAccess` (la règle d'accès unique : membre d'un des deux groupes ou invité, hors corbeille ; refus uniforme), `ConversationService` (démarrer, répondre, renommer, lire, lister), `ConversationMentionService` (valider les mentions, inviter les extérieurs), `ConversationGuestService` (retirer un invité), `ConversationTrashService` (corbeille et avis), `MemberSearchService` (liste après `@`), `ConversationNotifier` et `ConversationReminderService` (e-mails). Les contrôleurs sont découpés de la même façon (`ConversationApiController`, `ConversationTrashApiController`, `MemberApiController`).
- **E-mails (#180)** : à la création d'une conversation, **un seul e-mail à l'adresse de contact du groupe visé** (`ConversationNotifier`, mémorisé par `conversation_group_notices` : jamais deux fois) ; **relance** après 24 h sans lecture par le groupe (`ConversationReminderService`, lancée par `bin/send-reminders.php` toutes les heures, plage de jour, un rappel par message, un message de plus de 7 jours n'est plus relancé). « Lu par le groupe » = au moins un de ses membres a lu après le message. Jamais le titre ni le texte dans un e-mail ; une panne d'e-mail (SQL, gabarit, transport) n'annule ni ne fait échouer l'envoi du message ; l'objet passe par `HeaderText::oneLine` (pas d'injection d'en-tête). Reste à faire : mention (#178), retour après connexion. Chiffrement : #171.

## Point clé — concurrence sur les créneaux libérés

`MysqlSlotExceptionRepository::claim()` porte un `UPDATE ... WHERE status='liberee'` atomique, jamais un `SELECT` puis `UPDATE` séparés. Un `rowCount() === 0` signifie "déjà pris par quelqu'un d'autre" → 409, pas d'exception. C'est le test le plus important du projet.

## Modèle d'interaction

Navigation entre pages : rendu serveur classique (`PageController`, GET, templates PHP). Toute écriture (login, claim, CRUD admin) : XHR vers `Api/*Controller`, jamais de submit de formulaire natif avec reload. Détail complet dans le plan de conception initial (hors dépôt, conversation de cadrage projet).

## Génération de fichiers

|Fichier|Méthode|
|-|-|
|Migration SQL|écrite à la main dans `database/migrations/`, appliquée par `bin/migrate.php`|
|Entité/Service/Repository/Test|générés directement (voir `.claude/templates/*.stub` pour le squelette)|
