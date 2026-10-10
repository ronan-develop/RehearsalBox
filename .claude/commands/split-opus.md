---
description: Tâche difficile — comme /split, mais planifiée par Opus (conception délicate, plusieurs modules) ; l'exécution reste confiée aux agents Haiku
argument-hint: <n° de ticket | description de la tâche>
model: claude-opus-5-5
---

Tâche : $ARGUMENTS

Tu es l'**orchestrateur** (Opus). Lis `.claude/commands/split.md` et applique-le à la lettre, étapes 1 à 7 (dont la passe de refacto, étape 6), pour la tâche ci-dessus : même procédure, seul le modèle qui planifie change.

Ton rôle propre : les décisions de conception difficiles (frontières entre modules, schéma, sécurité, concurrence) sont à trancher TOI AVANT de découper, puis à figer dans des briefs si précis que Haiku n'a plus rien à décider. Ce que `split.md` interdit de déléguer (authentification, droits, concurrence SQL, transactions, e-mails, sécurité) reste interdit : tu l'écris toi-même. Opus coûte plus cher que Sonnet : si la tâche se révèle moyenne, dis-le et continue seul au plus court plutôt que de sur-planifier.
