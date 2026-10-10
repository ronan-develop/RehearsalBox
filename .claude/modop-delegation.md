# Modop — installer la méthode de délégation dans un autre projet

Méthode : **un planificateur (Sonnet, ou Opus si difficile) découpe une tâche en micro-tâches entièrement spécifiées ; des agents Haiku 5.5 les exécutent en parallèle ; le planificateur relit, teste et propose les commits.** But : aller plus vite et consommer moins de tokens, sans perdre les règles du projet. Éprouvée sur RehearsalBox (voir `.claude/delegation.md` pour son mode d'emploi).

Temps d'installation : ~15 minutes, dont 10 pour écrire les paramètres du projet.

## 1. Ce qui est portable (à copier tel quel)

| Fichier                              | Rôle                                                     | À adapter ?                                  |
|--------------------------------------|----------------------------------------------------------|----------------------------------------------|
| `.claude/commands/dispatch.md`       | `/dispatch` : choisit le mode et le lance                | Non (renvoie aux « Paramètres du projet »)   |
| `.claude/commands/split.md`          | `/split` : Sonnet planifie, Haiku exécute                | Non                                          |
| `.claude/commands/split-opus.md`     | `/split-opus` : Opus planifie, Haiku exécute             | Non                                          |
| `.claude/agents/haiku-worker.md`     | L'exécutant Haiku 5.5                                    | **Oui** : bloc « Règles du projet »          |
| `.claude/delegation.md`              | Mode d'emploi + règles + **Paramètres du projet**        | **Oui** : tableau « Paramètres du projet »   |

```bash
# depuis la racine du NOUVEAU projet (adapter le chemin source)
SRC=/home/ronan/code/php/RehearsalBox/.claude
mkdir -p .claude/commands .claude/agents
cp $SRC/commands/{dispatch,split,split-opus}.md .claude/commands/
cp $SRC/agents/haiku-worker.md .claude/agents/
cp $SRC/delegation.md .claude/
```

## 2. Ce qu'il faut écrire pour le projet (les seuls points propres au projet)

**a) `delegation.md`, section « Paramètres du projet »** — réécrire chaque ligne :

| Paramètre                          | Question à se poser                                                                                      |
|------------------------------------|-----------------------------------------------------------------------------------------------------------|
| Fichiers de conventions            | Quels fichiers un exécutant ou le planificateur doit-il lire avant de toucher au code ?                    |
| Zones sensibles (jamais Haiku)     | Où une erreur coûte cher : auth, droits, argent, migrations, concurrence, données personnelles, crypto ?   |
| Méthodologie de test               | TDD strict ? tests après ? Où sont les tests ?                                                             |
| Tests ciblés (autorisés aux Haiku) | Quelle commande lance UN test ou un fichier de test, vite, sans toucher à une ressource partagée ?        |
| Ressource partagée                 | Base de test, port, service externe, fichier unique : tout ce que deux exécutants ne peuvent pas utiliser en même temps |
| Contrôles de l'orchestrateur       | Suite complète, lint, analyse statique, build, prérequis (conteneur à démarrer)                            |
| Workflow git                       | Nom de branche, format de commit, branche principale protégée, qui confirme push/PR/merge                  |
| Checklist de refacto               | Où est la liste de critères de qualité du projet (si elle existe) ?                                        |

**b) `haiku-worker.md`, bloc « Règles du projet — À ADAPTER »** — remplacer par : langage et style imposés, règle de sécurité des données (ex. requêtes préparées), règle front/API éventuelle, et **la liste exacte des commandes de test autorisées** (c'est ce qui évite aux exécutants d'écraser une ressource partagée).

**c) `CLAUDE.md` du projet** — ajouter ce paragraphe (adapter la liste des zones sensibles) :

```markdown
### Délégation

Une demande qui modifie du code et n'est pas triviale (au-delà de 1-2 fichiers) suit la procédure de `.claude/commands/dispatch.md` : choisir le mode (direct, Sonnet + Haiku, Opus + Haiku), l'annoncer en une ligne, le lancer. Jamais de Haiku sur <zones sensibles du projet>. Détail : `.claude/delegation.md`.
```

Et une ligne dans l'index de CLAUDE.md : `| Délégation (/dispatch, /split, /split-opus) | .claude/delegation.md |`.

**d) `.claude/settings.json`** — autoriser les commandes de test ciblées. **Obligatoire** : les agents Haiku tournent en parallèle et ne peuvent pas demander d'autorisation, sans cela ils bloquent.

```json
{ "permissions": { "allow": ["Bash(<commande de test ciblé 1>)", "Bash(<commande de test ciblé 2>)"] } }
```

## 3. Vérifier l'installation (3 contrôles, dans cet ordre)

1. **L'agent démarre** : lancer l'agent `haiku-worker` avec « Test de démarrage, aucun outil, réponds avec ton identifiant de modèle ». Attendu : `claude-haiku-5-5`. Sinon, l'identifiant du frontmatter est faux.
2. **Les commandes sont vues** : `/dispatch`, `/split`, `/split-opus` apparaissent dans la liste des commandes de la session (en rouvrir une si besoin).
3. **Essai réel sur une tâche petite mais à 3 morceaux** (ex. un champ ajouté sur une couche modèle + service + test) : `/dispatch <description>`. Attendu : mode annoncé en une ligne, découpage montré, exécutants lancés dans UN seul message, diffs relus, rien de commité.

Si le 3 échoue, corriger d'abord les paramètres (zones sensibles, tests ciblés), pas les commandes.

## 4. Pièges déjà rencontrés (ne pas les redécouvrir)

- **Un sous-agent ne peut pas lancer d'autres agents.** L'orchestrateur est donc la session principale (une commande), jamais un agent. D'où `/split` en commande.
- **Une commande fixe son modèle** (`model:` du frontmatter) mais ne peut pas en changer en cours de route : un modèle différent selon la difficulté = deux commandes (`/split`, `/split-opus`) ou un agent lancé avec `model:` (c'est ce que fait `/dispatch` pour Opus).
- **Épingler l'identifiant du modèle Haiku** (`claude-haiku-5-5`) plutôt que l'alias `haiku` : l'alias peut pointer sur une autre version. À changer dans ce seul fichier à la prochaine version.
- **Ressource partagée = collisions silencieuses.** Deux suites de tests sur la même base se marchent dessus (symptôme : blocage sans erreur ou résultats faux). Un seul exécutant à la fois, ou aucun.
- **Un fichier, un propriétaire.** Deux exécutants sur le même fichier s'écrasent sans erreur.
- **Une micro-tâche mal spécifiée coûte plus qu'elle ne rapporte** : si l'exécutant doit explorer le dépôt ou prendre une décision, faire en direct.
- **La relecture du diff n'est pas optionnelle.** Haiku peut passer ses tests tout en s'écartant des conventions.
- **Les Haiku ne commitent jamais** ; les commits passent par le workflow du projet, sur validation.

## 5. Réglages si les résultats déçoivent

| Symptôme                                            | Réglage                                                                                           |
|-----------------------------------------------------|----------------------------------------------------------------------------------------------------|
| Haiku s'écarte des conventions                      | Renforcer le bloc « Règles du projet » du worker ; donner dans le brief un fichier voisin à imiter |
| Trop d'aller-retours de correction                  | Micro-tâches plus petites ; code exact dans le brief ; zone sensible reprise par le planificateur  |
| Pas d'économie visible                              | Seuil de délégation plus haut (≥ 4 morceaux) ; comptes rendus plus courts                          |
| Opus lancé trop souvent                             | Durcir le critère « difficile » dans `dispatch.md` (tableau des modes)                             |
| Conflits d'écriture entre exécutants                | Revoir le découpage : fichiers réellement disjoints                                                |

## 6. Prompt à coller dans l'autre projet

```text
Installe la méthode de délégation Sonnet/Opus → Haiku 5.5 dans ce projet. Source : /home/ronan/code/php/RehearsalBox/.claude (lis modop-delegation.md en entier, puis suis-le).

1. Copie dispatch.md, split.md, split-opus.md, haiku-worker.md et delegation.md aux bons endroits.
2. Explore ce projet (stack, tests, conventions, ressources partagées, zones sensibles) et réécris la section « Paramètres du projet » de delegation.md ET le bloc « Règles du projet » de haiku-worker.md. Ne devine pas : si une valeur n'est pas évidente, pose-moi la question.
3. Ajoute le paragraphe « Délégation » et la ligne d'index dans CLAUDE.md (crée-le s'il n'existe pas), et les commandes de test ciblées dans .claude/settings.json.
4. Fais les 3 contrôles du § 3 du modop et rapporte le résultat de chacun.

Contraintes : ne modifie aucun code applicatif, ne commite rien, ne pousse rien. Montre-moi les paramètres que tu as écrits avant de lancer le contrôle 3.
```
