# Audit d'architecture : trop de classes par dossier (#287)

Document produit par l'audit #287 (aucun fichier déplacé dans ce ticket). Il fixe **une règle**, dresse **l'état des lieux**, propose **une arborescence cible** avec ses arguments, **un plan de migration** et décrit **le garde-fou** déjà en CI.

## 1. La règle

> **Au plus 12 classes (fichiers PHP) directement dans un dossier de `src/`.** Les sous-dossiers comptent chacun à part ; interfaces, exceptions et enums comptent comme des classes. `bin/check-folders.php` échoue en CI au-delà.

Pourquoi 12 : 10 est trop serré pour un domaine comme la messagerie (14 dépôts MySQL) et pousserait à des sous-dossiers de 3 fichiers ; 15 laisse déjà un dossier illisible (on ne le parcourt plus d'un coup d'œil). 12 tient sur un écran. Le plafond est une constante (`bin/check-folders.php`) : le changer est une ligne si le propriétaire préfère 10 ou 15 (la liste d'exceptions se recalcule alors avec la commande du §6).

Ce qui est exempté : rien, par principe. Un dossier déjà trop plein est **toléré à son effectif actuel** (cliquet, comme le budget de taille des classes) : il ne peut plus grossir et son exception doit être retirée dès qu'il repasse sous le plafond.

## 2. État des lieux (242 fichiers dans `src/`, au 7 octobre 2026)

Fichiers PHP directement dans le dossier :

| Dossier | Fichiers | Plafond 12 |
|---|---:|---|
| `Service` | 42 | dépassé (exception figée) |
| `Entity` | 29 | dépassé |
| `Repository` | 27 | dépassé |
| `Repository/Contract` | 25 | dépassé |
| `Controller/Api` | 17 | dépassé |
| `Presenter` · `Service/Exception` | 16 · 16 | dépassés |
| `Security` | 14 | dépassé |
| `Http` | 10 · `Service/Contract` 9 · `Support` 8 · `Entity/Enum` 7 · `Controller` 6 | respectés |

**Domaines métier mélangés** dans ces dossiers (comptage par nom de classe, interfaces et exceptions incluses) :

| Domaine | Classes | Où elles sont aujourd'hui |
|---|---:|---|
| **Messagerie** (conversations, mentions, sourdine, invités, relances, corbeille, versions) | 78 | Service 20 · Repository 14 · Entity 12 · Contract 12 · Presenter 8 · Controller/Api 6 … |
| **Plateforme** (Http, Routing, Container, View, Database, Migration, Deploy, Support, Mail, sécurité générique) | 58 | déjà rangée **par type**, petits dossiers |
| **Comptes** (connexion, mots de passe, e-mail, profil, administration des utilisateurs, limitation de débit) | 41 | Service 10 · Security 6 · Repository 5 · Contract 5 · Exception 5 … |
| **Planning** (créneaux fixes, demandes d'échange, `TimeRange`, `Requester`) | 25 | Entity 6 · Presenter 4 · Service 3 · Api 3 … |
| **Groupes** (groupes, documents, gestionnaires, effectif) | 24 | Repository 4 · Contract 4 · Service 4 · Entity 3 … |
| **Réservations** (réservations libres, plan, notifications, politique) | 22 | Service 5 · Entity 4 · Presenter 3 · … |
| **Tableau de bord** (composition des autres domaines) | 3 | Entity 2 · Presenter 1 |

Constat : les dossiers par **type** (`Service`, `Entity`, `Repository`…) mélangent six domaines qui n'ont presque rien à voir ; en revanche chaque domaine, lui, est petit à l'exception de la messagerie.

**Mort ou à confirmer** (classes que rien ne référence en production : ni `src/`, ni `config/`, ni `bin/`, ni gabarits) :
- `Service/GroupManagerService`, `Repository/MysqlGroupManagerRepository`, `Repository/Contract/GroupManagerRepositoryInterface` (+ leurs deux tests) : **non câblés volontairement** (commit « le rôle de gestionnaire dans son propre service »). À câbler ou à supprimer : **décision du propriétaire**, un ticket dédié.
- `Http/RedirectResponse` : seulement dans un test de `SecurityHeaders`. Candidat à la suppression.
- Les Null Objects (`NoBookingNotifier`, `NoConversationMentions`, `NoGroupFilesPurger`, `NoNewConversationNotice`) sont des **valeurs par défaut utilisées** : à conserver.

**Déjà au budget de taille** (`config/size-budget-exceptions.php`) : seule `Entity/User.php` (218 lignes, 19 méthodes publiques) reste hors budget ; `MysqlGroupRepository` est au plafond de 10 méthodes publiques.

## 3. Arborescence cible

**Par domaine d'abord, par type ensuite** : `src/<Domaine>/{Entity,Repository,Service,Controller,Presenter,Exception}`.

Arguments :
- Un domaine **se lit, se teste et se change d'un bloc** : ajouter une règle de réservation ne touche que `src/Booking/`. Aujourd'hui, la même évolution disperse les fichiers dans cinq dossiers de 40 fichiers.
- Chaque sous-dossier reste **petit par construction** (12 classes suffisent pour une couche d'un domaine) ; la règle chiffrée devient naturelle au lieu d'être une contrainte.
- Le couplage entre domaines devient **visible** (un `use App\Booking\…` dans `src/Planning/` saute aux yeux) ; l'ordre de migration en découle.
- Contre-argument entendu : « le type d'abord est le standard des tutoriels » ; vrai, mais sans second cas d'usage ni framework qui l'impose, il n'apporte rien ici (le conteneur est explicite, `config/services.php`).

```
src/
├── Booking/          Entity · Repository(+Contract) · Service · Controller · Presenter · Exception
├── Planning/         idem (TimeRange, Requester, SlotException, RecurringSlot…)
├── Group/            idem
├── Account/          idem (connexion, mots de passe, e-mail, profil, utilisateurs, débit)
├── Messaging/        idem, avec Service/ éclaté en Conversation/ et Notification/ (relances, avis, Reminder*)
├── Dashboard/        Presenter (DashboardView, DashboardBookings…)
└── (plateforme, inchangée, par type)  Http · Routing · Container · View · Database · Migration · Deploy · Support · Mail · Security
```

Cas à trancher à la migration : `Messaging/Repository` (14 classes MySQL) dépasse 12 → sous-dossier par agrégat (`Notice/`, `Mention/`…) ou exception figée tant qu'il n'est pas coupé ; `Repository/Contract` suit son domaine (les interfaces sont dans le domaine du métier qui les consomme).

## 4. Plan de migration

Un **domaine par PR**, **déplacement pur** (`git mv`, changement de `namespace` et de `use`, aucun changement de comportement, tests inchangés hors `use`), du **moins couplé au plus couplé** :

| Ordre | Ticket | Domaine | Classes | Dossier(s) vidé(s) ou allégé(s) |
|---:|---|---|---:|---|
| 1 | #317 | **Groupes** (`src/Group`) ✅ | 27 | allège Repository, Contract, Entity |
| 2 | #316 | **Planning** (créneaux, échanges **et réservations libres**) + **Tableau de bord** ✅ | 47 | allège Entity, Presenter, Service |
| 3 | #318 | **Comptes** ✅ | 41 | allège Security, Service, Exception |
| 4 | #319 | **Messagerie** ✅ | 78 | vide Service, Repository, Contract, Entity |
| 5 | #320 | nettoyage | classes mortes (GroupManager, RedirectResponse) | 4 | décision du propriétaire |

Coût mesuré : **182 fichiers** importent `App\Repository\…` et **126** `App\Service\…` ; un domaine entier touche donc 30 à 90 fichiers (surtout des `use`). Outils : `git mv` + substitution des `namespace`/`use` par script, puis PHPStan et la suite complète comme filet.

Risques et parades :
- **Conflits avec des branches en cours** : migrer entre deux tickets, jamais pendant une PR ouverte sur le même domaine.
- **`config/services.php`**, **`config/routes.php`**, **`phpstan.neon.dist`**, **`config/size-budget-exceptions.php`**, **`config/folder-budget-exceptions.php`** : chemins et `use` à mettre à jour dans la même PR (le garde-fou de dossier signale une exception devenue obsolète).
- **Tests miroirs** : `tests/Service` (36), `tests/Repository` (26) suivent les domaines dans la même PR ; le plafond ne s'applique pas encore aux tests (à étendre quand les domaines sont migrés).
- **Déploiement** : aucun risque de comportement ; vérifier `composer dump-autoload -o` (le déploiement le fait) et l'amorçage de `public/index.php`.

**Ajustement décidé à la première migration** : les réservations libres ne forment pas un domaine à part. `SlotService` fusionne les réservations validées dans le planning (dépendance Planning vers Réservations) : deux domaines auraient créé un cycle. Elles rejoignent donc `src/Planning` (« occupation du local »). Les interfaces de dépôt et de service se rangent **à côté de leurs implémentations** dans le domaine (plus de dossiers `Contract/`), les enums dans `Entity/`, les exceptions dans `<Domaine>/Exception/`. `FieldValidationException`, socle de toutes les erreurs par champ, devient `src/Validation/`. Dans chaque domaine, les contrôleurs JSON sont dans `Controller/Api/` et les contrôleurs de pages dans `Controller/` (choix du propriétaire) ; les tests suivent le même rangement.

## 5. Garde-fou (déjà livré)

- `tools/FolderBudget.php` (testé : `tests/Tools/FolderBudgetTest.php`), lancé par `bin/check-folders.php` **en CI** (étape « Classes per folder budget »), à côté de `bin/check-size.php`.
- Exceptions figées dans `config/folder-budget-exceptions.php` (**liste vide depuis la migration de la messagerie** : plus aucun dossier au-dessus du plafond) : un dossier ne peut plus grossir, une exception devenue inutile **fait échouer** le contrôle jusqu'à ce qu'on la retire.

## 6. Mode d'emploi pour un déplacement

1. Créer le dossier du domaine et y `git mv` les classes (namespace `App\<Domaine>\<Couche>`).
2. Mettre à jour `use`, `config/services.php`, `config/routes.php`, tests.
3. `php bin/check-folders.php` : retirer de `config/folder-budget-exceptions.php` les dossiers qui repassent sous 12 (le message l'indique) ; ajuster l'effectif des autres.
4. PHPStan, `php bin/check-size.php`, suite complète, puis relire la checklist Refacto.
