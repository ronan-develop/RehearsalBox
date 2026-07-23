# Design Frontend

## Style : CSS maison, variables `--rb-*`

Pas de Tailwind/Bootstrap. `assets/css/base.css` porte les variables CSS custom properties (couleurs, espacement, rayons) et le reset. Palette sombre orientée rock/punk/metal/prog, pas de glassmorphism, pas de `backdrop-filter` décoratif.

## Variables principales (`--rb-*`)

|Rôle|Variable(s)|
|-|-|
|Fond|`--rb-bg` (`#121212`), `--rb-bg-2` (`#1a1a1a`)|
|Accent|`--rb-accent` (rouge `#e63946`)|
|Accent secondaire|`--rb-accent-2` (orange électrique ou violet néon)|
|Surface|`--rb-surface`, `--rb-surface-strong` (modales, tab-bar)|
|Bordure|`--rb-border`|
|Texte|`--rb-text`, `--rb-text-2`, `--rb-text-3`|
|Ombre|`--rb-shadow`, `--rb-shadow-lg`|
|Statuts|`--rb-ok`, `--rb-warn`, `--rb-err`|
|Espacement|`--rb-space-1` … `--rb-space-6`|
|Rayons|`--rb-radius-sm`, `--rb-radius-md`|

Dark mode : le projet est nativement sombre (pas de mode clair prévu au départ) — si un mode clair est ajouté plus tard, passer par `@media (prefers-color-scheme: light)` en exception, pas l'inverse.

## Typographie

Display condensée/anguleuse pour les titres, lisible standard pour le corps. **Toujours self-hosted (woff2)** — jamais de CDN externe (cohérent avec la CSP `default-src 'self'` et l'hébergement mutualisé).

## Règles

- Toute surface (modale, card, toolbar) utilise `var(--rb-surface)` / `var(--rb-border)` / `var(--rb-shadow-lg)` — jamais de couleur en dur
- Toujours une transition sur les éléments cliquables/survolables
- Toasts non bloquants pour tout retour d'action async (succès/erreur) — jamais `alert()`/`confirm()` natifs (mauvaise UX mobile)
- Confirmation d'action destructive : modale HTML/CSS maison pilotée en JS, jamais `confirm()` natif
- Zones tactiles suffisamment grandes (mobile-first) sur les boutons d'action (claim, libérer, supprimer)
