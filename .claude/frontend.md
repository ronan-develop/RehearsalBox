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
- Toasts non bloquants pour tout retour d'action async (succès/erreur) — jamais `alert()`/`confirm()` natifs (mauvaise UX mobile)
- Confirmation d'action destructive : modale HTML/CSS maison pilotée en JS, jamais `confirm()` natif
- Zones tactiles suffisamment grandes (mobile-first) sur les boutons d'action (claim, libérer, supprimer)

## Principe : serveur d'abord, JS quand c'est nécessaire (règle du propriétaire)

Le rendu et la **navigation** restent du **rendu serveur classique** (une URL = une page PHP) ; on ne construit pas une SPA. Le JS améliore l'existant : **XHR seulement quand il est nécessaire** (une action qui modifie des données, un rafraîchissement en direct), pas de routage côté client (`pushState`/`popstate`), pas de page vide remplie après coup. Quand une partie vivante doit se mettre à jour, le serveur renvoie des **fragments HTML rendus par le même gabarit PHP** (un seul endroit qui dessine), jamais du JSON que le client transformerait en DOM. Les **pages d'administration** restent peu dynamiques : liste rendue par le serveur, JS réduit aux actions.

## Composants Web (#181) — convention pour tout nouveau morceau d'interface

La messagerie est construite en **éléments personnalisés natifs** (standard des navigateurs : aucune dépendance, rien à maintenir) dans `public/assets/js/chat/`. Même convention pour tout nouveau composant.

- **Un composant = un morceau de gabarit PHP + un élément JS.** Le gabarit (`templates/<page>/_nom.php`, documenté par `@var`) rend l'élément et ses enfants côté serveur ; l'élément ajoute le comportement. DOM direct (pas de Shadow DOM) : le CSS du site, les gabarits et les attributs `data-*` continuent de s'appliquer ; les éléments d'enrobage sont `display: contents`.
- **Composant de présentation** (`rb-sidebar`, `rb-thread-header`, `rb-message-list`, `rb-composer`) : reçoit des données par méthodes (`setConversations()`, `render()`…), **émet des événements** (contrat dans `chat/events.js` : `composer:submit`, `header:rename`, `sidebar:select`…) et **ne parle jamais à l'API**. **Contrôleur** (`rb-chat`) : possède l'état, écoute les événements, appelle l'API. Un nouveau composant se branche sans toucher aux autres.
- **Asynchrone moderne** : `async/await` partout ; un `AbortController` par « session » (conversation ouverte) annule chargement et polling d'un coup ; boucles `while` + `sleep(ms, signal)` + `whenVisible()` (`chat/async.js`) au lieu de `setTimeout` récursifs ; pause quand l'onglet est caché.
- **Logique pure à part** (`chat/model.js`, testée sans DOM) ; rendu DOM dans `chat/view.js` ; appels réseau dans `chat/api.js` (toujours via `apiFetch`, CSRF).
- **Sécurité** : le texte venant d'un utilisateur n'est inséré que par `textContent`, jamais en HTML ; une couleur n'est appliquée que si elle valide `#rrggbb`.
- **Tests** : `node --test` pour la logique pure et le contrat d'événements ; les éléments eux-mêmes se vérifient dans un vrai navigateur (Chromium, mobile ET bureau). Le motif de `npm test` est entre guillemets : sans cela le shell n'exécute que les tests des sous-dossiers.
