# Layout CSS — référence rapide

## Structure HTML (templates/layout/base.php)

```
body
└── .rb-app
    ├── .rb-header       (logo, contexte utilisateur)
    └── .rb-content      (flex:1, overflow:auto — zone scrollable)
.rb-tab-bar               (fixed bottom-0, height:64px)
```

Pas de sidebar desktop : l'app est mobile-first et volontairement simple, une seule colonne de contenu quel que soit le viewport — pas de grille desktop à gérer séparément.

## Bottom nav / tab-bar

4 items : Dashboard / Mon groupe / Admin (si rôle admin) / Déconnexion.

```css
.rb-tab-bar { position: fixed; bottom: 0; height: 64px; z-index: 40; }
/* grid-template-columns: repeat(4, 1fr) ou repeat(3, 1fr) si pas admin */
```

Conséquence : tout conteneur pleine-hauteur doit soustraire 64px (`calc(100vh - 64px)`).

## Règles de scroll

- `.rb-content { overflow: auto; -webkit-overflow-scrolling: touch }`
- `.rb-app { height: calc(100vh - 64px) }`
- Ne jamais mettre `overflow` sur un conteneur parent de `.rb-content` — confiner le scroll à cette seule zone

## Breakpoints

|Breakpoint|Règle CSS|Usage|
|-|-|-|
|Mobile (cible principale)|par défaut, sans media query|layout de base|
|Desktop (confort, pas prioritaire)|`@media (min-width: 768px)`|largeur max du contenu centrée, pas de réorganisation profonde|

## Variables CSS clés

Voir `.claude/frontend.md` pour la liste complète des `--rb-*`.
