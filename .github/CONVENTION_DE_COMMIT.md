# Convention de Commit & Branches

## Format OBLIGATOIRE des commits

```txt
<emoji> <type>(<scope>): <sujet>
```

**Règles non négociables :**

- L'**emoji** est TOUJOURS présent en début de message
- Le **scope** doit être **explicite et concret** : nom de la classe, du module ou du composant concerné
  - ✅ `feat(FileUploadController)`, `fix(UserTest)`, `test(FileTest)`
  - ❌ `feat(file)`, `feat(api)`, `fix(tests)` ← trop vague
- Les commits sont **atomiques** : un commit = une responsabilité logique
- Ne jamais faire `git add .` en un seul bloc

---

## Workflow de branches — OBLIGATOIRE

**On ne commite JAMAIS directement sur `main`.**

```txt
main  ← branche stable, toujours verte (tests passants)
 └── feat/<nom-explicite>     ← nouvelle fonctionnalité
 └── fix/<nom-explicite>      ← correction de bug
 └── refactor/<nom-explicite> ← refactorisation
 └── test/<nom-explicite>     ← ajout de tests
 └── chore/<nom-explicite>    ← outillage, config, docs
```

### Règles de branche

| Règle                 | Détail                                                     |
|------------------------|--------------------------------------------------------------|
| Créer une branche     | Pour tout changement, même petit                           |
| Nom explicite         | `feat/file-browser`, `fix/swagger-assets`, pas `feat/truc` |
| Merge dans main       | Uniquement quand les tests passent                         |
| Supprimer après merge | Nettoyer les branches mergées                              |

### Workflow type

```bash
# 1. Partir de main à jour
git checkout main && git pull

# 2. Créer la branche
git checkout -b feat/NomExplicite

# 3. Commiter par groupes logiques (jamais git add .)
git add src/fichier-concerne.php
git commit -m "✨ feat(NomClasse): description courte"

# 4. Merger dans main quand c'est prêt et testé
git checkout main
git merge --no-ff feat/NomExplicite
git branch -d feat/NomExplicite
```

---

## Types de commits

| Emoji | Type     | Quand                                        |
|-------|----------|-----------------------------------------------|
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
