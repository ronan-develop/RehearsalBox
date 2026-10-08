# Design Frontend

## Style : CSS maison, variables `--rb-*`

Pas de Tailwind/Bootstrap. `assets/css/base.css` porte les variables CSS custom properties (couleurs, espacement, rayons) et le reset. Palette sombre orientée rock/punk/metal/prog, pas de glassmorphism, pas de `backdrop-filter` décoratif.

## Variables principales (`--rb-*`)

|Rôle|Variable(s)|
|-|-|
|Fond|`--rb-bg` (`#15151a`), `--rb-bg-2` (`#1a1a20`)|
|Accent|`--rb-accent` (braise/terracotta `#b5654a`)|
|Accent secondaire|`--rb-accent-2` (`#8a8478`, gris chaud désaturé)|
|Surface|`--rb-surface`, `--rb-surface-strong` (modales, tab-bar)|
|Bordure|`--rb-border`|
|Texte|`--rb-text`, `--rb-text-2`, `--rb-text-3`|
|Ombre|`--rb-shadow`, `--rb-shadow-lg`|
|Statuts|`--rb-ok`, `--rb-warn`, `--rb-err`|
|Espacement|`--rb-space-1` … `--rb-space-6`|
|Rayons|`--rb-radius-sm`, `--rb-radius-md`|
|Texture pierre|`--rb-stone-1` … `--rb-stone-flame`, classes `.rb-stone-surface`/`.rb-stone-panel` (usage parcimonieux) et `.rb-stone-panel--ember` (réservée au header dashboard, ne pas généraliser)|

Dark mode : le projet est nativement sombre (pas de mode clair prévu au départ) — si un mode clair est ajouté plus tard, passer par `@media (prefers-color-scheme: light)` en exception, pas l'inverse.

## Typographie

Arimo pour tout texte lu/fonctionnel (titres h1/h2/h3 et corps). **Toujours self-hosted (woff2)** — jamais de CDN externe (cohérent avec la CSP `default-src 'self'` et l'hébergement mutualisé).

Exception circonscrite au logo/branding "#B27" : "A Dripping Marker" (`public/assets/fonts/a-dripping-marker.woff2`) sert le watermark décoratif du dashboard (`aria-hidden="true"`) et le logo `.rb-auth-logo` des pages login/register — toujours et uniquement pour le graffiti "#B27" lui-même, jamais pour un autre titre ou texte lu générique.

## Règles

- Toute surface (modale, card, toolbar) utilise `var(--rb-surface)` / `var(--rb-border)` / `var(--rb-shadow-lg)` — jamais de couleur en dur
- Toujours une transition sur les éléments cliquables/survolables
- Toasts non bloquants pour tout retour d'action async (succès/erreur) via `showToast(message, type)` (`core/toast.js`, façade de `<rb-toast-region>`, #329) — jamais `alert()`/`confirm()` natifs (mauvaise UX mobile)
- Confirmation d'action destructive : `<rb-confirm-dialog>` (#326), jamais `confirm()` natif. Le gabarit `templates/partials/confirm-dialog.php` (posé une fois par page) contient l'élément **natif `<dialog>`** : le composant `ui/rb-confirm-dialog.js` y met le texte (`textContent`), ouvre en modal (focus piégé, Échap, restitution du focus : le navigateur) et répond par une promesse (`confirmAction(message, {title, confirmLabel, cancelLabel})`). Focus initial sur « Annuler ». La logique pure (paragraphes, clic hors de la fenêtre = « Annuler ») est dans `ui/confirm-dialog.js`, testée.
- Zones tactiles suffisamment grandes (mobile-first) sur les boutons d'action (claim, libérer, supprimer)

## Principe : serveur d'abord, JS quand c'est nécessaire (règle du propriétaire)

Le rendu et la **navigation** restent du **rendu serveur classique** (une URL = une page PHP) ; on ne construit pas une SPA. Le JS améliore l'existant : **XHR seulement quand il est nécessaire** (une action qui modifie des données, un rafraîchissement en direct), pas de routage côté client (`pushState`/`popstate`), pas de page vide remplie après coup. Quand une partie vivante doit se mettre à jour, le serveur renvoie des **fragments HTML rendus par le même gabarit PHP** (un seul endroit qui dessine), jamais du JSON que le client transformerait en DOM. Les **pages d'administration** restent peu dynamiques : liste rendue par le serveur, JS réduit aux actions.

## Composants Web (#181) — convention pour tout nouveau morceau d'interface

La messagerie est construite en **éléments personnalisés natifs** (standard des navigateurs : aucune dépendance, rien à maintenir) dans `public/assets/js/messaging/chat/`. Même convention pour tout nouveau composant.

- **Un composant = un morceau de gabarit PHP + un élément JS.** Le gabarit (`templates/<page>/_nom.php`, documenté par `@var`) rend l'élément et ses enfants côté serveur ; l'élément ajoute le comportement. DOM direct (pas de Shadow DOM) : le CSS du site, les gabarits et les attributs `data-*` continuent de s'appliquer ; les éléments d'enrobage sont `display: contents`.
- **Rôles** : `rb-sidebar` (rafraîchit la liste rendue par le serveur), `rb-thread-header` (titre modifiable, choix de l'émetteur d'un brouillon), `rb-message-list` (défilement, ajout d'un fragment HTML), `rb-composer` (saisie, signal d'écriture, correction, citation) sont des composants légers qui **émettent des événements** (contrat dans `messaging/chat/events.js`) et **ne parlent jamais à l'API** ; `rb-chat` (contrôleur) écoute, appelle l'API, ajoute les fragments reçus et sérialise les opérations sur le fil. La **navigation** (conversations, archives, retour) n'est **pas** dans ce contrat : ce sont de vrais liens.
- **Asynchrone moderne** : `async/await` partout ; un `AbortController` par « session » (conversation ouverte) annule chargement et polling d'un coup ; boucles `while` + `sleep(ms, signal)` + `whenVisible()` (`messaging/chat/async.js`) au lieu de `setTimeout` récursifs ; pause quand l'onglet est caché.
- **Logique pure à part** (`messaging/chat/thread/model.js` : cadence du polling, limite du signal d'écriture, testée sans DOM) ; appels réseau dans `messaging/chat/api.js` (toujours via `apiFetch`, CSRF). **Aucun rendu côté client** : les textes (heures, « Vu par », « écrit… ») sont calculés et dessinés par PHP.
- **Sécurité** : le texte d'un utilisateur est échappé par `e()` dans les gabarits PHP ; le JS n'insère que le HTML reçu du serveur (fragments issus de ces gabarits), jamais du texte brut converti en HTML ; une couleur n'est émise que si elle valide `#rrggbb` (`SafeColor`).
- **Tests** : `node --test` pour la logique pure et le contrat d'événements ; les éléments eux-mêmes se vérifient dans un vrai navigateur (Chromium, mobile ET bureau). Le motif de `npm test` est entre guillemets : sans cela le shell n'exécute que les tests des sous-dossiers.

## Formulaires asynchrones : `<rb-async-form>` (#327)

Tout formulaire qui modifie des données est enveloppé : `<rb-async-form endpoint="/api/…" method="POST"><form>…champs…</form></rb-async-form>` (14 formulaires). Le composant (`core/rb-async-form.js`, logique pure dans `core/async-form.js`) intercepte le submit, envoie le JSON par `apiFetch` (jamais de rechargement, `preventDefault` même mal configuré), écrit les erreurs par champ dans `[data-field-error="nom"]` et pose `aria-invalid` sur le champ, met `aria-busy` sur le formulaire et désactive ses boutons pendant l'envoi, et ignore un second envoi en vol (jeton CSRF unique, session régénérée à la connexion). **Contrat d'évènements inchangé**, émis sur le composant : `async-success` (détail : la réponse) et `async-error` (détail : `{ message, fields, status }`). `form` et `reset()` sont exposés ; `display: contents` : la mise en page ne change pas. Il n'y a **rien à initialiser** : une balise insérée plus tard (carte de groupe créée en JS) s'active seule. Les consommateurs sélectionnent `rb-async-form[endpoint="…"]`. Une page de retour (`data-next` de la connexion) vit sur le composant. Pas de `<form is="…">` : Safari ne gère pas les éléments natifs personnalisés. Le garde-fou `tests/View/AsyncFormMarkupTest.php` interdit le retour de `data-async`.

## Onglets : `<rb-tabs>` (#328)

`<rb-tabs [persist="clé"] [hide-inactive]>` enveloppe un balisage ARIA rendu par le serveur : un `role="tablist"` de boutons `role="tab"` (`id`, `data-tab="nom"`, `aria-controls`, `aria-selected`) et des `role="tabpanel"` (`id`, `aria-labelledby`). **Aucun sélecteur global** : le composant ne voit que ses propres onglets et panneaux (reliés par `aria-controls`), deux jeux d'onglets d'une même page ne se connaissent pas. Il pose `aria-selected` et un tabindex itinérant, gère flèches, Début et Fin (`ui/tabs.js`, logique pure testée), marque le panneau actif `data-active` (le CSS décide de ce qu'il masque : le planning n'en montre qu'un sous 768 px) ou, avec `hide-inactive`, le masque par `hidden` (demandes de créneau). `data-ready` n'est posé que par le composant : sans JavaScript tout reste visible. Avec `persist`, le dernier onglet est mémorisé sur l'appareil (facultatif, sans effet si le stockage est refusé). Émet `tabs:change` { name, index }. Les onglets de l'administration et des mesures sont de **vrais liens** (navigation serveur), pas des `rb-tabs`. Garde-fou : `tests/View/TabsMarkupTest.php`.

## Notifications : `<rb-toast-region>` (#329)

`core/rb-toast-region.js` (logique pure dans `core/toasts.js`) est une **région `aria-live`** (`role="status"`, `aria-live="polite"`) créée **au chargement** de la page par `app.js` (`ensureToastRegion`) : une région live doit exister avant son premier message pour être annoncée. Les messages s'**empilent** (quatre au plus, le plus ancien s'efface), un **même message** (texte et type) n'est pas répété (on prolonge celui qui est affiché : un double clic ne fait pas deux bandeaux), chaque message a un bouton **« Fermer »**, s'efface seul (4 s, 7 s pour une erreur) et son minuteur est **suspendu** au survol et au focus. Une erreur porte `role="alert"` (annoncée tout de suite). Le texte passe par `textContent`, jamais en HTML. L'API `showToast` des 11 appelants ne change pas ; sous `node --test` (pas de DOM) elle ne fait rien. Pas d'action « annuler » dans le message : rien ne l'exige (YAGNI).

## Confort de la messagerie (#187) — sans SPA

Améliorations d'usage qui respectent « serveur d'abord » (aucun routage ni rendu côté client, aucune dépendance) :

- **Ouverture sur le dernier message par le CSS seul** : le conteneur du fil est en `flex-direction: column-reverse` (un seul enfant, la liste, qui garde son ordre). Le navigateur ancre le défilement en bas dès le premier affichage, sans flash et **sans JS**. L'origine du défilement est le bas : `scrollTop` vaut 0 tout en bas et s'éloigne de 0 en remontant (le signe dépend du navigateur : on ne compare que la distance, `isNearBottom`). Le JS ne garde que le saut vers « Messages non lus ».
- **Composeur découpé** : `rb-composer.js` orchestre trois pièces à une responsabilité chacune : `mention-picker.js` (suggestions de mentions, listbox accessible), `composer-drafts.js` (synchronise le champ avec le magasin de brouillons) et `composer-quote.js` (aperçu de la citation) ; la logique pure est dans `mentions.js`, `quote.js` et `swipe.js` (testées avec `node --test`).
- **Gestes sur les bulles (écran tactile)** : `swipe-row.js` est le seul code de geste (mouvement nettement horizontal, le défilement vertical n'est jamais capté, déclenchement au relâchement après les trois quarts de la distance, aucune sélection de texte volée) ; `swipe-edit.js` (vers la **gauche**, corriger sa bulle récente) et `swipe-quote.js` (vers la **droite**, citer n'importe quelle bulle) n'en sont que la configuration. L'icône d'action n'est visible que pendant le glissement (`rb-chat-message--swiping-right`). Sur ordinateur : boutons « Répondre » et crayon au survol ou au clavier.
- **Citer (#214)** : `rb-message-list` émet `message:quote-request` { id, author, text } ; `rb-chat` appelle `composer.startQuote()` ; l'envoi porte `replyTo` (l'identifiant seulement). Un clic sur la citation d'une bulle amène le message cité à l'écran et le fait clignoter ; le lien d'ancre fait le même trajet sans JavaScript. Le texte de l'aperçu passe par `textContent`.
- **Brouillons** (`messaging/chat/composer/drafts.js`) : `localStorage`, clé `rb-draft:<utilisateur>:<conversation>`, **7 jours**, **effacés à la déconnexion** (poste partagé), écrits à la saisie (300 ms) et à la fermeture de page, effacés à l'envoi (réécrits si l'envoi échoue). Tout est dans un `try/catch` : stockage absent, plein ou refusé = la page fonctionne sans. Un brouillon est remis dans le champ via `.value`, jamais comme HTML ; il n'est jamais envoyé au serveur avant l'envoi.
- **Position de la liste** (`messaging/chat/thread/scroll-memory.js`) : `sessionStorage`, un nombre de pixels par liste (actives / archivées), restaurée à l'affichage. Le rafraîchissement automatique de la liste ne remplace pas une ligne ouverte ou en cours de glissement.
- **« ↓ Nouveaux messages »** : quand des messages arrivent pendant qu'on relit l'historique, le fil n'est pas forcé vers le bas ; un bouton le signale (zone tactile de 44 px) et ramène en bas.
- **Transitions de page natives** : `@view-transition { navigation: auto }` dans `@media (prefers-reduced-motion: no-preference)` ; ignoré par les navigateurs qui ne le gèrent pas.
- **Mobile** : `enterkeyhint="send"`, focus conservé dans le champ après l'envoi (le clavier reste ouvert), hauteurs en `dvh`.

## Organisation de `public/assets/js/` (même logique que `src/`)

Un point d'entrée, `app.js` (seul script chargé par les pages), et des dossiers **par domaine** : `core/` (api, html, forms, toast, viewport, weekdays), `ui/` (composants et effets transverses : tornpaper, logo, confirmation, sélecteur d'horaire…), `account/`, `group/`, `planning/` (+ `dashboard/` pour le bloc de demandes et le carrousel, + `booking/` pour la réservation), `messaging/` (+ `chat/` : `components/`, `composer/`, `thread/`). Chaque test `*.test.js` est à côté du module qu'il teste. Les imports sont relatifs ; `npm test` découvre `**/*.test.js`.
