# Délégation Sonnet/Opus → Haiku (`/dispatch`, `/split`, `/split-opus`)

Pour les tâches **moyennes ou difficiles** : Sonnet (ou Opus) conçoit et découpe en micro-tâches, des agents Haiku 5.5 les exécutent en parallèle. Objectif : aller plus vite et consommer moins de tokens, sans toucher aux règles du projet.

## Mode d'emploi

**Cas courant : tu donnes ton prompt (ou un n° de ticket), le reste est automatique.**

```text
/dispatch 334                 ← ou /dispatch "texte de la tâche", ou simplement ton prompt dans le chat
  → il lit, annonce le mode en une ligne, puis le lance
  → tu valides le découpage « micro-tâche → fichiers » (si plusieurs modules)
  → les agents Haiku 5.5 exécutent en parallèle, le planificateur relit et teste
/git                          ← commits atomiques proposés un à un ; push / PR / merge sur ton ordre
```

| Mode choisi         | Quand                                            | Qui fait quoi                                                      |
|---------------------|--------------------------------------------------|--------------------------------------------------------------------|
| **Direct**          | 1-2 fichiers, correction, code sensible          | La session seule, TDD, sans agent                                  |
| **Sonnet + Haiku**  | ≥ 3 morceaux, conception évidente                | Sonnet découpe → Haiku 5.5 exécute → Sonnet relit et teste         |
| **Opus + Haiku**    | Conception à trancher, plusieurs modules         | Agent Opus conçoit → Sonnet valide et lance → Haiku exécute        |

**Forcer un mode** (si tu sais déjà) : `/split <…>` impose Sonnet + Haiku, `/split-opus <…>` impose Opus + Haiku.

**Prompt sans commande** : pour une demande qui modifie du code et n'est pas triviale, CLAUDE.md demande à la session d'appliquer `/dispatch`. Le modèle de la session n'est alors pas forcé à Sonnet (seule la commande le fixe) : pour la garantie Sonnet, tape `/dispatch`.

## Pièces

| Fichier                         | Rôle                                                                                         |
|---------------------------------|----------------------------------------------------------------------------------------------|
| `.claude/commands/dispatch.md`  | `/dispatch <n° ticket \| description>` — aiguille ET lance (`model: sonnet`) : direct, Sonnet+Haiku ou Opus+Haiku|
| `.claude/commands/split.md`     | `/split <n° ticket \| description>` — l'orchestrateur (`model: sonnet`), tâche moyenne       |
| `.claude/commands/split-opus.md`| `/split-opus <…>` — même procédure (lit `split.md`), planifiée par Opus : tâche difficile    |
| `.claude/agents/haiku-worker.md` | L'exécutant (`model: claude-haiku-5-5`, identifiant épinglé : à changer ici à la prochaine version) : une sous-tâche bornée, test d'abord, compte rendu de 15 lignes |

## Pourquoi ce sont des commandes et pas des agents

Un sous-agent ne peut pas en lancer d'autres. L'orchestrateur doit donc être la session principale : `/dispatch` et `/split` fixent son modèle à Sonnet pour ce tour, les `haiku-worker` en sont les enfants directs.

## Règles de l'orchestrateur (génériques)

Les règles de l'exécutant lui-même (test d'abord, aucune commande git qui écrit, sobriété, périmètre) sont écrites UNE fois, dans `.claude/agents/haiku-worker.md`. Ici, ce qui revient à l'orchestrateur :

- **Un fichier, un propriétaire** par `/split` : sinon écrasements silencieux dans l'arbre de travail commun.
- **Ressource partagée à un seul utilisateur** (base de test, port, fichier de verrou) : réservée à un exécutant au plus ; la suite complète n'est lancée que par l'orchestrateur.
- **Git** : commits après validation de l'utilisateur ; pas de push, PR ou merge sans confirmation (sauf autorisation donnée pour ce ticket).

## Paramètres du projet — la section à réécrire dans un autre projet (avec le bloc « Règles du projet » de `haiku-worker.md`)

Valeurs de RehearsalBox (PHP pur, JS vanilla, PDO/MySQL). Quand une autre section ou un autre fichier dit « voir Paramètres du projet », c'est ici.

| Paramètre                          | Valeur RehearsalBox                                                                                                                       |
|------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| Fichiers de conventions            | `CLAUDE.md` ; `.claude/architecture.md` (si `src/`), `.claude/tdd.md`, `.claude/frontend.md` et `layout.md` (si visuel)                    |
| Zones sensibles (jamais Haiku)     | Authentification, droits (IDOR), concurrence SQL (`UPDATE … WHERE status=…` atomique), transactions, e-mails, sécurité                    |
| Méthodologie de test               | TDD RED → GREEN → REFACTOR (`.claude/tdd.md`)                                                                                              |
| Tests ciblés (autorisés aux Haiku) | `./vendor/bin/phpunit --filter X` (test sans `#[Group('db')]`), `composer test:quick`, `node --test <fichier>.test.js`                    |
| Ressource partagée                 | Base de test MariaDB unique, port 3307 (`docker-compose.test.yml`) : un seul PHPUnit avec base à la fois                                  |
| Contrôles de l'orchestrateur       | Conteneur de test démarré, puis `./vendor/bin/phpunit --colors=always`, `npm test`, `phpstan analyse`, `php-cs-fixer --dry-run`, `php bin/check-size.php` |
| Workflow git                       | Branche `<type>/#<n>-<slug>`, commits `<emoji> <type>(<scope>): …` via `/git`, jamais sur `main`, merge simple, confirmation avant push/PR/merge |
| Suivi du ticket                    | Board GitHub projet #11 (REST) : Backlog → Ready → **In progress dès le début du code** → Refacto (checklist relue, refacto fait DANS le ticket) → Done au merge. **Piège** : le workflow du projet « Pull request linked to issue » repasse la carte en In progress à l'ouverture d'une PR liée : la remettre en Refacto juste après, puis relire. Syntaxe de mise à jour : mémoire `feedback-board-workflow` |
| Checklist de refacto               | Description de la colonne « Refacto » du board GitHub projet #11 (lue en REST) : OOP, SRP, Factory, Strategy, KISS, DRY, YAGNI             |
| Règles de code des exécutants      | Bloc « Règles du projet » de `.claude/agents/haiku-worker.md` (à réécrire en même temps)                                                  |

## Économie de tokens — où elle vient vraiment

- Haiku fait le volume (écrire tests et code d'une couche déjà conçue) ; Sonnet ne lit que les comptes rendus et les diffs.
- Briefs **courts mais complets** : fichiers autorisés, signatures, cas de test. Un exécutant qui doit explorer le dépôt coûte plus qu'il ne rapporte.
- Comptes rendus plafonnés à 15 lignes : ils reviennent dans le contexte de Sonnet.
- Gain nul ou négatif si le découpage est mauvais (sous-tâches liées, conception laissée à Haiku) : voir « Choisir le mode » dans `.claude/commands/dispatch.md`.

## Limite connue

Haiku peut livrer un code qui passe ses tests mais s'écarte des conventions : la relecture du diff par Sonnet (étape 5 de `/split`) n'est pas optionnelle.
