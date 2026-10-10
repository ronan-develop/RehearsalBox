---
description: Point d'entrée unique — lit la tâche, choisit le mode (direct, Sonnet+Haiku, Opus+Haiku) et le LANCE. Donne juste ton prompt ou ton n° de ticket.
argument-hint: <n° de ticket | description de la tâche>
model: sonnet
---

Tâche : $ARGUMENTS

Tu es l'**aiguilleur ET l'orchestrateur**. Tu choisis le mode, tu le dis en une ligne, puis tu l'exécutes sans demander à l'utilisateur de retaper quoi que ce soit.

## 1. Lire (économe)

- Un numéro → `gh issue view <n>`. Sinon la description telle quelle. Appliquer le contexte de `/ticket` : `git status`, branche courante (signaler des changements non commités ou un autre ticket en cours).
- 2 à 4 recherches ciblées (`grep`/`ls`) pour situer le code : dossiers de `src/`, `public/assets/js/`, `templates/`, `tests/`. Pas de lecture de fichiers entiers à ce stade.
- **Suivi du ticket** : si l'argument est un numéro, passer sa carte en « In progress » AVANT de coder, puis en « Refacto » avant le merge (et la remettre en Refacto après l'ouverture de la PR : un workflow la repasse en In progress), « Done » au merge. Détail dans « Suivi du ticket » de « Paramètres du projet ».

## 2. Choisir le mode

| Mode                | Quand                                                                                                         |
|---------------------|---------------------------------------------------------------------------------------------------------------|
| **Direct**          | 1 à 2 fichiers, correction, texte, CSS isolé ; morceaux liés entre eux ; zone sensible au cœur de la tâche     |
| **Sonnet + Haiku**  | ≥ 3 morceaux sur des fichiers distincts, conception déjà évidente                                              |
| **Opus + Haiku**    | Plusieurs modules, conception à trancher (frontières, schéma, nouveau flux), ticket ambigu ou risqué           |

Zone sensible (liste dans « Paramètres du projet » de `.claude/delegation.md`) : jamais confiée à Haiku, tu l'écris toi-même. Doute entre deux modes : le moins cher, sauf si l'erreur coûte un retour en arrière (schéma, sécurité).

Annonce le choix en UNE ligne (« Mode : Sonnet + Haiku — 5 micro-tâches, couches distinctes ») et continue.

## 3. Exécuter

### Direct
Travailler toi-même, avec la méthodologie de test du projet (« Paramètres du projet »). Pas d'agent.

### Sonnet + Haiku
Appliquer à la lettre `.claude/commands/split.md`, étapes 1 à 7 (tu es l'orchestrateur : tu découpes, tu lances les `haiku-worker` en parallèle, tu relis les diffs, tu lances toi seul la suite complète).

### Opus + Haiku
1. Lancer UN agent de conception : `Agent` avec `subagent_type: Plan` et `model: opus`. Son brief : la tâche, les fichiers repérés, les règles du projet (`CLAUDE.md` et les fichiers de conventions de « Paramètres du projet »), et la demande « tranche la conception et rends le découpage en micro-tâches selon `.claude/commands/split.md` étape 3 : pour chacune, fichiers autorisés, code ou signatures exactes, cas de test ; marque celles qui touchent une zone sensible (je les écrirai moi-même) ». Il ne modifie aucun fichier.
2. Relire son plan (tu restes responsable) ; corriger ce qui contredit une convention du projet.
3. Continuer à partir de `split.md` étape 3 (validation du découpage par l'utilisateur), puis 4 à 7 avec ce plan.

## 4. Passe de refacto (toujours, avant les commits)

Quel que soit le mode, tests verts : appliquer la « Passe de refacto » de `.claude/delegation.md` (carte en Refacto, checklist relue sur le diff, corrections dans le ticket, résultat annoncé en une ligne). Ne pas proposer les commits avant.

## 5. Points d'arrêt (toujours)

- **Validation du découpage** par l'utilisateur avant de lancer les Haiku si la tâche touche plusieurs modules ou si le ticket est ambigu (cf. `split.md`). Une tâche monomodule bien décrite part sans attendre.
- **Git** : aucun commit automatique. Quand tout est vert, proposer les commits atomiques selon le workflow git du projet (« Paramètres du projet ») ; jamais de commit sur la branche principale ; pas de `push`, PR ni merge sans confirmation.
- Une sous-tâche Haiku qui échoue deux fois est reprise par toi, pas relancée une troisième fois.
