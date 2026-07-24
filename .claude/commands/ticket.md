Démarre le traitement d'un ticket GitHub Issue à partir de son seul numéro (ex. `/ticket 20`).

Ce comportement s'applique aussi quand l'utilisateur envoie directement `#<numéro>` seul en tout début de conversation (ex. `#20`), sans passer par la commande explicite — considérer cela comme un déclencheur équivalent.

## Contexte à charger, dans l'ordre

1. **Le ticket lui-même** : `gh issue view <numéro>` (titre, corps, labels, commentaires). C'est la source de vérité du besoin — ne pas deviner ce qui n'y est pas écrit.

2. **L'état du dépôt** : `git status` et `git branch --show-current`. Si des changements non commités traînent ou qu'on est déjà sur une branche liée à un autre ticket, le signaler avant de continuer.

3. **Les conventions du projet** : relire `CLAUDE.md` et suivre les liens pertinents selon la nature du ticket :
   - `.claude/architecture.md` si le ticket touche `src/`
   - `.claude/tdd.md` — le cycle RED → GREEN → REFACTOR est obligatoire, ne pas l'oublier
   - `.claude/frontend.md` / `.claude/layout.md` si le ticket est visuel/CSS
   - `.claude/git-conventions.md` pour le nommage de branche et les commits

4. **Le code existant concerné** : à partir du titre/corps du ticket, identifier les fichiers `src/` (et `assets/js/` le cas échéant) déjà en place sur le sujet, plutôt que de repartir de zéro. Utiliser Explore si la zone n'est pas évidente immédiatement.

## Ensuite

- Résumer en 3-4 lignes : ce que demande le ticket, l'impact (fichiers/modules concernés), et le plan TDD envisagé.
- Ne pas commencer à coder avant validation implicite ou explicite de l'approche si le ticket est ambigu ou touche plusieurs modules.
- Créer la branche dédiée (voir `.claude/git-conventions.md` pour le nommage) avant le premier commit — jamais de commit direct sur `main`.
