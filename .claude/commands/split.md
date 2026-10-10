---
description: Tâche moyenne — Sonnet découpe en sous-tâches indépendantes confiées en parallèle à des agents Haiku (haiku-worker), puis relit et intègre
argument-hint: <n° de ticket | description de la tâche>
model: sonnet
---

Tâche : $ARGUMENTS

Tu es l'**orchestrateur** (Sonnet). Tu conçois, tu découpes, tu relis ; les agents `haiku-worker` (Haiku) exécutent. But : aller plus vite et dépenser moins de tokens, sans rien céder aux règles du projet (voir `.claude/delegation.md`).

## 1. Comprendre (toi seul, avant toute délégation)

- Si l'argument est un numéro : `gh issue view <n>` (source de vérité). Sinon, la description telle quelle.
- `git status`, branche courante (signaler des changements non commités ou un autre ticket en cours). Lire les conventions utiles de la section « Paramètres du projet » de `.claude/delegation.md` (fichiers de conventions, checklist de refacto si la tâche en est une).
- Repérer le code existant concerné. Lire les fichiers qui commandent la conception : c'est ce que Haiku ne saura pas faire.

## 2. Décider s'il faut déléguer — sinon, travailler seul

Ne PAS déléguer (faire en direct, c'est moins cher qu'un brief) si :
- la tâche tient en 1 fichier, ou en moins de ~3 sous-tâches ;
- les sous-tâches se touchent (même fichier, une dépend du résultat d'une autre) ;
- elle touche à une **zone sensible** du projet (liste dans « Paramètres du projet ») : tu l'écris toi-même, sans Haiku.

Sinon, délègue.

## 3. Découper

Produis la liste des sous-tâches (3 à 8) ; chacune est une **micro-tâche** que Haiku exécute sans réfléchir :
- **Petite** : un fichier (ou un fichier + son test), quelques dizaines de lignes, un seul comportement. Un morceau plus gros, ou qui comporte encore un choix à faire, est redécoupé par toi ou tranché par toi avant de partir.
- **Entièrement spécifiée** : le brief donne le code attendu à l'identique quand c'est court (signature, SQL préparé, structure du test), ou à défaut le nom exact de chaque méthode, ses entrées/sorties et chaque cas de test. Aucune décision laissée à l'exécutant.
- **Indépendante** : jeu de fichiers **disjoint** des autres (un fichier = un seul propriétaire). Une couche par exécutant convient bien : un repository + son test, un service + son test, un composant JS + son test, un gabarit.
- **Mécanique** : tu as déjà décidé des noms, signatures et comportements. Si une sous-tâche demande encore de réfléchir à la conception, c'est à toi de la trancher d'abord.
- **Testable seule** : son test est précisé (cas nominal, accès non autorisé, cas limite, erreur).

Montre le découpage à l'utilisateur en quelques lignes (sous-tâche → fichiers) et **attends son accord** avant de lancer si la tâche touche plusieurs modules ou si le ticket est ambigu.

## 4. Déléguer — un seul message, tous les exécutants en parallèle

Un appel `Agent` par sous-tâche, `subagent_type: haiku-worker`, dans le MÊME message. Brief court et autonome (l'exécutant n'a aucun accès à cette conversation) :
- l'objectif en une phrase ;
- les **fichiers autorisés** (et seulement ceux-là) ; un fichier voisin à imiter comme modèle ;
- les signatures / noms exacts, le comportement attendu, les cas de test à écrire ;
- « Test d'abord (RED puis GREEN), aucune commande git qui écrit, compte rendu en moins de 15 lignes. »

Ressource partagée (base de test, port, fichier de verrou : voir « Paramètres du projet ») : **un seul** exécutant peut s'en servir, et seulement si tu l'as écrit dans son brief. Les autres lancent des tests ciblés qui n'en dépendent pas.

## 5. Intégrer (toi)

1. Lire le compte rendu de chacun, puis **relire le diff** des fichiers modifiés (`git diff`) : conventions du projet, pas d'abstraction en trop, règles de sécurité des données, RED réellement constaté.
2. Corriger toi-même les petits écarts ; ne renvoyer à un exécutant que si c'est long et mécanique.
3. Lancer toi seul les contrôles de « Paramètres du projet » (prérequis d'environnement, suite complète, contrôles de qualité concernés).
4. Résumer à l'utilisateur : ce qui a été fait, ce qui a été délégué, ce qui reste.

## 6. Git — jamais en automatique

Aucun exécutant ne commite. Quand tout est vert, propose le découpage en commits atomiques selon le workflow git du projet (« Paramètres du projet ») et attends la validation. Jamais de commit sur la branche principale ; pas de `git push`, de PR ni de merge sans confirmation.
