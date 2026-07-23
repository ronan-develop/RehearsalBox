Prépare et effectue un ou plusieurs commits selon la convention du projet définie dans `.github/CONVENTION_DE_COMMIT.md`.

## Étapes obligatoires

1. **Analyse les changements** — exécute `git status` et `git diff` pour identifier tous les fichiers modifiés.

2. **Relis le diff stagé** — avant tout commit, relis attentivement les changements pour t'assurer qu'aucun secret, clé, mot de passe ou donnée sensible n'est inclus (`.env.local`, clés JWT, tokens…). Si tu en trouves, stoppe et avertis l'utilisateur.

3. **Regroupe les fichiers par responsabilité logique** — ne jamais faire `git add .` en un bloc. Chaque commit doit représenter une seule responsabilité.

4. **Pour chaque groupe**, propose un commit au format :
   ```
   <emoji> <type>(<scope>): <sujet>
   ```
   - Emoji TOUJOURS présent (voir tableau ci-dessous)
   - Scope = nom de la classe, du module ou du composant (jamais `feat(file)` ou `feat(api)`)
   - Sujet court, à l'impératif, en français

5. **Attends la validation** de l'utilisateur avant d'exécuter chaque `git add` + `git commit`.

## Tableau des types

| Emoji | Type     | Quand                                        |
|-------|----------|----------------------------------------------|
| ✨    | feat     | Nouvelle fonctionnalité                      |
| 🔧    | fix      | Correction de bug                            |
| 📖    | docs     | Documentation uniquement                     |
| ♻️    | refactor | Refactorisation sans nouvelle feature ni fix |
| ⚡    | perf     | Amélioration des performances                |
| ✅    | test     | Ajout/correction de tests                    |
| 🏗️    | build    | Système de build, dépendances                |
| 🏭    | ci       | Configuration CI, scripts de déploiement     |
| 🛠️    | chore    | Outillage, config, nettoyage                 |
| 🎨    | style    | Formatage, espaces (pas de logique)          |
| 🔒    | security | Correctifs de sécurité                       |
| ⏪    | revert   | Annulation d'un commit                       |
| 🚧    | WIP      | Travail en cours (éviter sur main)           |
| 📋    | chore    | Mise à jour avancement, notes                |

## Rappels

- Ne jamais commiter directement sur `main`
- Ne jamais pusher sans confirmation explicite de l'utilisateur
- Ne jamais ouvrir de PR sans confirmation explicite de l'utilisateur
