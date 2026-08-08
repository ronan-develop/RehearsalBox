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

Exception strictement circonscrite : "A Dripping Marker" (`public/assets/fonts/a-dripping-marker.woff2`) sert uniquement le watermark décoratif `#B27` du dashboard (`aria-hidden="true"`, jamais du texte lu) — ne pas la présenter comme une deuxième police "display" généralisée.

## Règles

- Toute surface (modale, card, toolbar) utilise `var(--rb-surface)` / `var(--rb-border)` / `var(--rb-shadow-lg)` — jamais de couleur en dur
- Toujours une transition sur les éléments cliquables/survolables
- Toasts non bloquants pour tout retour d'action async (succès/erreur) — jamais `alert()`/`confirm()` natifs (mauvaise UX mobile)
- Confirmation d'action destructive : modale HTML/CSS maison pilotée en JS, jamais `confirm()` natif
- Zones tactiles suffisamment grandes (mobile-first) sur les boutons d'action (claim, libérer, supprimer)
