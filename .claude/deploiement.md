# Déploiement — o2switch (hébergement mutualisé, sous-compte dédié)

Aucun secret ni identifiant d'infrastructure dans ce fichier (dépôt public) : les valeurs réelles vivent dans `.secrets` (local, ignoré par git) et dans `config/config.local.php` sur le serveur (0600, hors webroot). `<...>` = placeholder.

## Principe

Déploiement par **releases** en SSH depuis le poste de dev, sans démon ni binaire tiers. Seuls les fichiers suivis par git (HEAD) sont envoyés (`git archive`) : ni `.secrets`, ni `config.local.php`, ni tests.

```text
~/rehearsalbox/
├── releases/<horodatage>-<sha>/   ← code + vendor/ (composer install --no-dev sur le serveur)
├── current -> releases/<...>      ← bascule atomique
├── shared/config.local.php        ← 0600, généré par bin/generate-config.php
├── shared/storage/                ← documents des groupes (inscriptible)
├── shared/well-known/             ← validation du certificat
└── backups/                       ← dumps (0700) : db-<horodatage UTC>.sql.gz quotidiens (14 conservés), pre-<release>.sql.gz avant migration (7 conservés)
~/<racine-du-domaine>/public -> ~/rehearsalbox/current/public   ← seule partie exposée
```

`src/`, `config/`, `database/`, `storage/` restent hors webroot.

## Prérequis (une fois)

- Sous-compte cPanel dédié, domaine ajouté avec pour **racine du document `<dossier-domaine>/public`** (vérifier dans *Domaines* : sinon le site répond 404/403).
- Version PHP **8.4** (web et CLI) ; extensions : `pdo_mysql` (via mysqlnd), `mbstring`, `fileinfo`, `openssl`, `iconv`, `intl`, `opcache`. `/usr/local/bin/php -v` doit afficher 8.4.
- Base MariaDB + utilisateur créés dans le cPanel.
- Clé SSH dédiée générée en local, **clé publique** importée et autorisée dans le cPanel, adresse IPv4 du poste autorisée. Fichier `ssh_config` local avec un alias (hors dépôt).
- SPF/DKIM valides pour le domaine d'envoi (cPanel › Email Deliverability).
- Certificat Let's Encrypt par AutoSSL (sans wildcard).
- `.secrets` : `PROD_DB_HOST`, `PROD_DB_PORT`, `PROD_DB_DATABASE`, `PROD_DB_USER`, `PROD_DB_PASSWORD`, `MAILER_DSN` (`sendmail://default`), `MAILER_FROM` (adresse `no-reply@<domaine>`), `APP_URL` (URL publique `https://<domaine>`, utilisée pour les liens envoyés par e-mail).

## Déployer

```bash
export RB_SSH_CONFIG=<chemin/ssh_config> RB_DOCROOT_LINK=<dossier-domaine>/public RB_SECRETS_FILE=<chemin/.secrets>
./bin/deploy.sh          # contrôles (voir ci-dessous), puis envoi, install, sauvegarde, migrations, bascule
```

**Contrôles (#300)** : si la CI est **verte sur le commit déployé** (toutes ses exécutions terminées avec succès, vérifié via `gh`, `bin/lib/ci-status.sh`), le script ne rejoue pas phpunit ni npm test et ne lance que `composer audit` (l'étape l'indique). Au moindre doute — `gh` absent ou en erreur, aucune exécution, une en cours ou en échec — il retombe sur les contrôles locaux complets. `RB_FORCE_LOCAL_CHECKS=1` les impose quand même. L'audit des dépendances n'est jamais sauté (hors `RB_SKIP_CHECKS=1`).

**Progression (#277)** : le script annonce **11 étapes numérotées** avec une barre (`[3/11] ███░░░ 27%  composer install … (+12 s)`, `bin/lib/progress.sh`) ; une étape sautée (`RB_SKIP_CHECKS=1`, `config.local.php` déjà présent) compte quand même et est marquée « ignorée » ; la dernière ligne donne la durée totale. La barre n'affiche que le nom des étapes : jamais une variable d'environnement, un chemin ni un identifiant d'hébergement. **Suivi dans Claude Code** : l'interface n'affiche d'un moniteur d'évènements que son **titre**, pas les lignes qu'il émet (vérifié sur une capture du propriétaire) ; un moniteur ne sert donc qu'à Claude. Pour que le propriétaire **voie** la progression, même en tâche de fond : lancer `./bin/deploy.sh` en tâche de fond avec son journal (`> journal 2>&1`), puis enchaîner de **courtes lectures du journal** (les lignes `[n/11]`) et **écrire la barre dans un message texte à chaque nouvelle étape** (les messages texte sont ce qui s'affiche à l'écran). Ne jamais recopier autre chose que les lignes de la barre.

Étapes : contrôles locaux → envoi de la release → `composer install --no-dev` avec le PHP CLI **explicite** + vérification `Nothing to install` → génération de `config.local.php` si absent (`RB_REGEN_CONFIG=1` pour forcer) → dump si la base contient des tables → `bin/migrate.php` → rendu à blanc de `GET /login` en CLI → bascule de `current` → purge d'OPcache → contrôle de la release servie. Si une étape échoue, `current` n'est pas modifié. **Jamais** `database/seed.php` en production.

## Comptes (sans fixtures)

**Page admin « Utilisateurs »** (#138) : onglet **Groupes**, puis le lien « Gérer les utilisateurs → » en haut de la page (la barre du bas reste à 5 entrées). On y crée un compte (e-mail, nom, rôle, groupe facultatif), on désactive/réactive un compte et on débloque un compte verrouillé. **Aucun mot de passe n'est choisi ni affiché** : le compte reçoit un secret aléatoire inutilisable, et la personne utilise « Mot de passe oublié » sur la page de connexion pour définir le sien à sa première connexion. On ne peut ni se désactiver soi-même ni désactiver le dernier admin actif ; un compte désactivé ne peut plus se connecter et ses sessions sont fermées.

En ligne de commande (premier admin, ou sans accès à la page) :

```bash
php bin/create-user.php <email> <nom> <admin|musicien>                       # sans mot de passe connu (cas normal)
RB_USER_PASSWORD='<mot-de-passe>' php bin/create-user.php <email> <nom> admin  # avec un mot de passe provisoire
```

Le mot de passe, s'il y en a un, passe par l'environnement (jamais en argument). La connexion se fait avec l'**e-mail**. **Pas d'inscription publique** (#137) : `/register` et `POST /api/auth/register` n'existent plus. Les groupes sont créés depuis l'écran Groupes.

## Tâche planifiée : relances de la messagerie (#180, #178)

`bin/send-reminders.php` envoie, **en deux temps** (relances de groupe puis relances de mention, deux lignes de bilan, puis une ligne sur l'oubli des anciennes versions de messages corrigés, #225), à l'adresse de contact d'un groupe **une relance** quand un message de l'autre côté est resté **24 h sans lecture** par ce groupe (jamais le contenu du message), seulement entre 9 h et 20 h (heure locale `app.timezone`) ; hors plage il ne fait rien et les relances dues partent le matin. Un message de plus de 7 jours n'est plus relancé. La **relance de mention** (#178) part à l'adresse du compte d'une personne taguée qui n'a pas lu la conversation 24 h après l'e-mail de mention (une seule par e-mail de mention, jamais le contenu) ; la même plage de jour s'applique. Le script s'arrête en erreur (code 1) si l'un des deux envois échoue. Idempotent : peut être relancé sans doublon.

Installée **une fois** (entrée `0 * * * *`, par SSH avec `crontab`, ou dans le cPanel *Tâches cron* si l'hébergeur le propose), avec le PHP CLI explicite du déploiement. La commande porte un **garde** : tant que la release active ne contient pas le script, elle ne fait rien (aucune erreur avant le premier déploiement qui l'embarque).

```bash
cd "$HOME/rehearsalbox/current" && [ -f bin/send-reminders.php ] && /usr/local/bin/php bin/send-reminders.php >> "$HOME/rehearsalbox/shared/reminders.log" 2>&1
```

- L'installation est **idempotente** (relancer remplace l'entrée, jamais de doublon) ; l'ancienne table cron est sauvegardée dans `shared/crontab.bak`. Vérification : `crontab -l | grep -c send-reminders` doit valoir 1.
- La commande passe par le lien `current` : elle suit toujours la release active, sans rien changer à chaque déploiement.
- Sortie : une ligne de bilan (`n envoyée(s), n échec(s), n ignorée(s)`), **sans adresse ni contenu** ; code de sortie non nul en cas d'échec d'envoi (réessayé à l'exécution suivante).
- Journaux (#193) : le script écrit lui-même son bilan dans `storage/logs/cron.log` (donc `shared/storage/logs/`, hors webroot, **avec rotation automatique** par taille) et le site écrit ses incidents dans `storage/logs/app.log`. Lecture : `php bin/tail-log.php cron` / `app`. La redirection `reminders.log` de la commande ci-dessus ne reçoit plus que les erreurs fatales de PHP (à purger de temps en temps, elle n'a pas de rotation). Le niveau se règle dans `config.local.php` (`logging.level`).
- Aucun secret dans la commande : la configuration (transport e-mail, adresse d'expédition, fuseau) vient de `config.local.php`.
- Un e-mail immédiat part aussi à la création d'une conversation (sans cron) ; si le cron n'est pas installé, seules les relances manquent. Les conversations **antérieures** au déploiement des e-mails n'en reçoivent aucun (migration 017).

## Collecte des mesures (#195)

`bin/collect-metrics.php` relève l'état du serveur et purge les anciennes mesures. À lancer **toutes les heures** par une seconde entrée cron, installée comme celle des relances (idempotente, ancienne table sauvegardée dans `shared/crontab.bak`, garde sur la présence du script) :

```bash
cd "$HOME/rehearsalbox/current" && [ -f bin/collect-metrics.php ] && /usr/local/bin/php bin/collect-metrics.php >> "$HOME/rehearsalbox/shared/reminders.log" 2>&1
```

Avant la première collecte en production, ajouter dans `shared/config.local.php` (0600, jamais dans le dépôt) : `metrics.secret` (chaîne aléatoire longue, **sans elle aucune adresse n'est conservée**), `metrics.backup_dir` (dossier des sauvegardes de la base) et `metrics.viewer_email` (le compte autorisé, #196). La migration 031 s'applique au déploiement. Rétention : évènements 30 jours, agrégats et instantanés 90 jours. Les alertes (#199) partent de la même tâche : un e-mail de synthèse au compte `metrics.viewer_email` quand un indicateur est rouge, au plus toutes les `metrics.alerts.min_gap_hours` heures par type (12 par défaut) ; `metrics.alerts.enabled = false` les coupe. Le journal de la collecte est `storage/logs/collect.log`.

## Réinitialisation de mot de passe

- Pages publiques `/forgot-password` et `/reset-password?token=…` ; API `POST /api/auth/forgot-password` et `/api/auth/reset-password`.
- Jeton aléatoire de 256 bits, **stocké haché** (SHA-256) dans `password_resets` (migration 010), valable 1 heure, **à usage unique** (`UPDATE … WHERE used_at IS NULL AND expires_at > :now`). Un nouveau jeton annule les précédents ; 3 demandes par heure et par compte au maximum.
- Réponse **identique** que le compte existe ou non (pas d'énumération). Le lien du mail est construit depuis `APP_URL` (`app.base_url`), **jamais** depuis l'en-tête `Host`.
- La page de réinitialisation envoie `Referrer-Policy: no-referrer` et `Cache-Control: no-store` (le jeton est dans l'URL).
- En-têtes de sécurité globaux (CSP, anti-iframe, HSTS…) : voir `.claude/architecture.md`. Après un déploiement, `curl -sI` la page de connexion et vérifier leur présence ; HSTS est à 30 jours, à allonger une fois le HTTPS validé de bout en bout. **Cookie de session** : `curl -sI https://<domaine>/login | grep -i set-cookie` doit montrer `__Host-rbsid` avec `Secure` (un `rbsid` sans `Secure` en HTTPS est un défaut de câblage, #244) ; le premier déploiement qui change ce nom déconnecte tout le monde une fois.
- Premier déploiement de la fonctionnalité : `APP_URL` doit être dans le fichier de secrets et la configuration serveur régénérée (`RB_REGEN_CONFIG=1 ./bin/deploy.sh`) ; la migration 010 est appliquée par le déploiement (sauvegarde préalable automatique).
- Limite connue : les sessions déjà ouvertes ne sont pas fermées après un changement de mot de passe (sessions PHP natives).

## Changement de mot de passe, sessions et alerte (#108)

- Page « Mon mot de passe » (`/account/password`, lien « Compte » dans la barre du bas) ; `POST /api/auth/change-password`. L'utilisateur visé est celui de la **session**, jamais un identifiant de la requête. Un mot de passe actuel faux compte comme un échec de connexion (mêmes limites : 5 échecs, verrouillage 15 min).
- **Sessions versionnées** : `users.session_version` (migration 011), copiée dans la session à la connexion et comparée à chaque requête. Un changement de mot de passe (ou « Ce n'est pas moi ») incrémente la version : les autres appareils sont déconnectés à leur prochaine requête, l'appareil courant reste connecté (session régénérée). Les sessions ouvertes avant la migration valent 0 : personne n'est déconnecté au déploiement.
- **Alerte** envoyée après un changement, avec un lien « Ce n'est pas moi » (jeton de 24 h, à usage unique, stocké haché dans `password_resets` avec `purpose = 'alert'`, migration 012). Le lien ouvre une page de **confirmation** (`/account/secure`) ; l'action n'a lieu qu'au `POST /api/auth/secure-account`, jamais sur un GET.
- « Ce n'est pas moi » **verrouille** le compte (7 jours), ferme toutes les sessions et envoie un lien de réinitialisation. L'ancien mot de passe n'est **pas** restauré : l'auteur du changement le connaît forcément.
- Déploiement : les migrations 011 et 012 sont appliquées automatiquement (sauvegarde préalable).

## Chiffrement des messages (#171)

Le texte de la messagerie (messages, anciennes versions corrigées, titres) est chiffré **en base** (libsodium). **Les clés sont dans `shared/message-keys.json`** (droits 600, hors dépôt, hors base, hors racine web), relié à chaque release (`config/message-keys.json`) comme `config.local.php`. Un déploiement ne le régénère jamais : **les messages restent lisibles d'une release à l'autre**.

**Garde-fous du déploiement (`bin/deploy.sh`)**
- Premier passage : le fichier est créé **sur le serveur** (`bin/message-keys.php init`) ; la clé n'est jamais affichée ni transmise.
- À chaque passage, **avant la bascule** : `bin/message-keys.php check` déchiffre un échantillon de la base. Clé perdue, fichier absent ou corrompu : **le déploiement s'arrête, la release n'est pas basculée** et les messages restent lisibles.
- Le déploiement **ne chiffre pas** les anciens messages et n'affiche aucune clé (volontaire : voir la procédure).
- `bin/rollback.sh` **refuse** de revenir à une release d'avant le chiffrement (elle lirait du chiffré comme du texte), sauf `RB_FORCE_ROLLBACK=1`.

**Mise en service (une seule fois, dans cet ordre)**
1. Déployer (le fichier de clés est créé ; tant que rien n'est rattrapé, les anciens messages restent lisibles en clair et les nouveaux sont chiffrés : mode « transition »).
2. **Sauvegarder la clé hors du serveur AVANT toute autre étape** : `ssh <serveur> 'php ~/rehearsalbox/current/bin/message-keys.php export' > sauvegarde-cles.json`, puis ranger ce fichier dans **KeePass** (pièce jointe d'une entrée). O2switch (hébergement mutualisé, cPanel) n'offre pas de coffre de secrets : KeePass reste local. **Ne jamais la ranger avec les sauvegardes de la base** (`backups/` est sur le serveur, dans le même compte que la clé).
3. Chiffrer l'existant (la sauvegarde `backups/pre-<release>.sql.gz` du déploiement fait foi) : `php bin/encrypt-messages.php --dry-run` (compte), puis `php bin/encrypt-messages.php` (idempotent, sans écraser un message corrigé pendant le passage).
4. Quand il ne reste rien en clair : `php bin/message-keys.php strict` (le texte non chiffré est alors refusé).

**Rotation de clé** (sur soupçon de fuite, pas périodique) : `php bin/message-keys.php rotate` (ajoute une clé et la rend courante, les anciennes restent pour lire), puis `php bin/encrypt-messages.php` (réécrit avec la nouvelle), puis **sauvegarder à nouveau** (`export`). Une ancienne clé ne se supprime qu'une fois tout réécrit (vérifier avec `check`).

**Restauration** (serveur neuf) : `php bin/message-keys.php import < sauvegarde-cles.json` (refuse d'écraser un fichier existant), puis `check`. Après la restauration d'un dump d'AVANT le chiffrement : `php bin/message-keys.php transition`, puis `encrypt-messages.php`, `check`, `strict` (voir « Restaurer la base — mode opératoire »).

**Perte de la clé = messages définitivement illisibles** : c'est le prix du chiffrement. Vérifier de temps en temps que la sauvegarde KeePass existe et que `export` donne le même fichier.

## Sauvegardes de la base (#167)

Un seul script, `bin/backup-db.php`, sert au déploiement et au dump quotidien : `--kind=daily` (`db-<horodatage UTC>.sql.gz`, **14 gardés**) ou `--kind=pre-deploy --label=<release>` (`pre-<release>.sql.gz`, **7 gardés**, lancé par `bin/deploy.sh` avant les migrations ; un échec arrête le déploiement). `--dir=<dossier>` est obligatoire, `--skip-if-empty` évite un dump d'une base vide (premier déploiement).

**Garanties** : le dump est écrit dans un fichier **provisoire**, **vérifié** (gzip valide, une instruction `CREATE TABLE` par table, ligne de fin `-- Dump completed`), puis renommé (atomique) ; la **rotation ne passe qu'après une réussite** (un dump raté ne supprime jamais une bonne sauvegarde) et ne touche que les fichiers de son type ; un fichier existant n'est jamais écrasé. Dossier en 0700, fichiers en 0600, hors racine web. Les identifiants passent par un fichier d'options **temporaire en 0600** : jamais en argument, ni dans la sortie, ni dans un journal ; connexion en utf8mb4 (le serveur est en latin1 par défaut).

**Dump quotidien (cron cPanel)**, à installer **une fois** (sur ordre) à côté de la tâche des relances (heure du serveur) :

```cron
30 3 * * * cd "$HOME/rehearsalbox/current" && [ -f bin/backup-db.php ] && /usr/local/bin/php bin/backup-db.php --kind=daily --dir="$HOME/rehearsalbox/backups" --skip-if-empty >> "$HOME/rehearsalbox/shared/backup.log" 2>&1
```

Le journal ne contient que des noms de fichiers. Un code de sortie 1 (« Sauvegarde ÉCHOUÉE ») signale un problème : relancer à la main (`php bin/backup-db.php …`) et lire le message. La date de modification de `backup.log` prouve que la tâche tourne.

**Copie hors serveur** : `RB_SSH_CONFIG=<chemin> ./bin/fetch-backup.sh` (depuis le poste de dev) rapatrie le dernier dump quotidien (`--pre` : le dernier d'avant déploiement) dans `~/rehearsalbox-backups` (0700, **jamais dans le dépôt**, vérifié par `gzip -t`, 30 gardés ; `RB_BACKUP_LOCAL_DIR` / `RB_BACKUP_LOCAL_KEEP` pour changer). À lancer régulièrement : seule une copie hors du serveur protège d'une perte du compte d'hébergement.

**Un dump seul ne suffit pas à relire les messages** : le texte de la messagerie y est chiffré (#171) et **la clé n'est pas dans le dump**. Il faut aussi la sauvegarde de la clé (KeePass, à part, voir « Chiffrement des messages »).

## Restaurer la base — mode opératoire (#167, #171)

À suivre dans l'ordre, sans sauter d'étape. Un outil scripté (confirmation, dump préalable automatique, nettoyage) est prévu au ticket #241 ; d'ici là, tout est manuel et volontairement prudent.

**0. Avant toute chose**
- **Quel est le besoin ?** *Revenir à l'état d'un jour* (restauration complète : on perd ce qui a été écrit depuis) **ou** *récupérer quelques lignes* (groupe, compte, message supprimés) **sans** perdre le reste : dans ce second cas, ne restaure PAS la base de production, passe par la base temporaire (étape B).
- **Quel dump ?** `ls -l ~/rehearsalbox/backups` : `db-<horodatage UTC>.sql.gz` (quotidien) ou `pre-<release>.sql.gz` (juste avant un déploiement). Contrôle-le : `gzip -t <fichier>`. Le contenu d'un dump est à l'heure indiquée dans son nom.
- **La clé est-elle là ?** `php bin/message-keys.php check` doit répondre « Clés vérifiées » sur la base actuelle. Sur un **serveur neuf**, restaure d'abord la clé depuis KeePass : `php bin/message-keys.php import < sauvegarde-cles.json`.
- **Ce dump est-il d'avant ou d'après le chiffrement des messages (mise en service du 10/10/2026, ticket #171) ?** Les dumps `db-*` à venir sont tous chiffrés ; les `pre-20261010120354…`, `pre-20261010124228…` et `pre-20261010134000…` sont **d'avant** (messages en clair). Cela change l'étape 6.

**A. Restauration complète (la base de production revient à l'état du dump)**
1. **Dump de l'état actuel d'abord** (le « retour du retour ») : `php bin/backup-db.php --kind=pre-deploy --label=<AAAAMMJJHHMMSS>-avant-restauration --dir="$HOME/rehearsalbox/backups"` (l'horodatage : 14 chiffres, maintenant). Ne continue pas s'il échoue.
2. **Essai à blanc dans la base temporaire** `sc2ron2cuba_restore` (étape B, 1 à 3) : si l'import échoue ou si les effectifs sont faux, on s'arrête là, la production n'a pas été touchée.
3. **Prévenir** les utilisateurs : pas de mode maintenance, la coupure dure le temps de l'import (quelques secondes à quelques minutes).
4. **Vider la base de production**, puis **importer** : `gunzip -c <dump> | mariadb --defaults-extra-file=<fichier d'options 0600> sc2ron2cuba_rehearsalbox`. Vider = supprimer toutes ses tables (l'utilisateur de l'application n'a pas le droit de supprimer la base elle-même) ; le dump recrée tout (`DROP TABLE IF EXISTS` inclus). Le fichier d'options contient les identifiants (jamais en argument) : droits 600, à supprimer après.
5. **Remettre le schéma à jour** si le dump est d'avant des migrations : `php bin/migrate.php` (la table `migrations_log` revient avec le dump, les migrations manquantes se rejouent).
6. **Messages chiffrés** : `php bin/message-keys.php check`.
   - Dump **d'après** le chiffrement : « Clés vérifiées », rien d'autre à faire.
   - Dump **d'avant** : le mode strict refuse le clair et la messagerie renverrait une erreur. Enchaîne : `php bin/message-keys.php transition` (rouvre la transition) → `php bin/encrypt-messages.php --dry-run` (compte) → `php bin/encrypt-messages.php` → `php bin/message-keys.php check` → `php bin/message-keys.php strict` (referme).
   - Dump chiffré avec une **ancienne clé** (après une rotation) : elle doit être dans le fichier de clés (elles y restent) ; `check` le prouve.
7. **Vérifier le site** : se connecter, ouvrir une conversation, envoyer un message ; `php bin/send-reminders.php` ne doit rien envoyer d'absurde (les relances reposent sur des dates : vérifier qu'aucune rafale ne part). OPcache : aucune purge nécessaire (le code n'a pas changé).
8. **Si ça tourne mal** : restaurer le dump de l'étape 1 de la même façon (c'est le retour du retour).

**B. Récupérer des lignes précises, sans toucher à la production** (base temporaire `sc2ron2cuba_restore`, même utilisateur, tous droits)
1. S'assurer qu'elle est **vide** (elle contient de vraies données dès qu'on y importe : à vider après usage).
2. `gunzip -c <dump> | mariadb --defaults-extra-file=<options> sc2ron2cuba_restore` (la base temporaire est en latin1 par défaut, sans importance : les tables du dump portent leur propre jeu de caractères utf8mb4).
3. Vérifier : même nombre de tables que le dump (`CREATE TABLE` compté par `gunzip -c <dump> | grep -c '^CREATE TABLE'`), effectifs plausibles.
4. Copier les lignes voulues d'une base à l'autre dans UNE connexion (l'utilisateur voit les deux) : `INSERT INTO sc2ron2cuba_rehearsalbox.<table> SELECT * FROM sc2ron2cuba_restore.<table> WHERE <condition>;` — respecter l'ordre des clés étrangères (parent avant enfant : groupe avant ses membres, conversation avant ses messages) et ne jamais écraser une ligne existante (`INSERT IGNORE` ou condition explicite). Les messages se recopient **tels quels (chiffrés)** : la même clé les lit.
5. **Vider la base temporaire** (supprimer ses tables) : elle contient des données personnelles.

**Ce qu'on ne fait jamais** : restaurer par-dessus la production sans le dump de l'étape A1 ; utiliser une clé de chiffrement autre que celle des messages pour un dump (elle n'y est pas) ; supprimer une ancienne clé du fichier tant qu'un dump qui l'utilise est conservé ; lancer `bin/encrypt-messages.php` avant d'avoir sous la main la sauvegarde de la clé.

**Cas de la copie sur le poste** (`bin/fetch-backup.sh`) : un dump y est lisible sans la clé, **sauf les messages** (chiffrés) ; pour les relire, il faut la clé de KeePass ET le dump.

## Retour arrière

```bash
RB_SSH_CONFIG=<chemin/ssh_config> ./bin/rollback.sh   # current -> release précédente (3 conservées)
```

La base n'est pas restaurée automatiquement : la commande de restauration depuis `backups/pre-<release>.sql.gz` est affichée, à lancer à la main.

## OPcache et lien symbolique

L'hébergeur valide OPcache sur le chemin du lien `current` (`opcache.revalidate_path=Off`), qui ne change pas d'une release à l'autre : après la bascule, l'ancien HTML et les anciennes routes peuvent rester servis alors que le CSS/JS sont déjà neufs, sans aucune erreur.

`bin/deploy.sh` s'en protège après la bascule :
1. **Purge** : un script PHP jetable au nom aléatoire (`opcache-reset-<hex>.php`) est déposé dans `current/public`, appelé en HTTPS (`opcache_reset()`), puis supprimé aussitôt. Si la purge échoue, un avertissement est affiché.
2. **Contrôle** (`bin/verify-release.sh`) : chaque réponse porte `X-Release`, empreinte opaque (12 premiers caractères du sha256) de l'identifiant de release, lue dans le fichier `RELEASE` écrit au déploiement (absent en développement : pas d'en-tête). Si la valeur attendue n'est pas servie après plusieurs tentatives, le script **échoue** (code ≠ 0) au lieu d'annoncer un succès.

Si le contrôle échoue : `bin/rollback.sh`, ou purger à la main puis relancer `bin/verify-release.sh <url>/login <empreinte>`.

## Mon compte : nom affiché et adresse e-mail (#161, #164)

Page **Mon compte** (entrée « Compte » de la navigation) : nom affiché modifiable, changement d'**adresse e-mail** et changement de mot de passe.

**Changement d'e-mail** en deux temps : (1) l'utilisateur saisit la nouvelle adresse et son **mot de passe actuel** ; un lien de confirmation (1 heure, usage unique, jeton haché dans `email_changes`) part vers la **nouvelle** adresse, la réponse est identique que l'adresse soit libre ou déjà utilisée (pas d'énumération de comptes) ; limite de 3 demandes par heure et par compte. (2) Le clic ouvre `/account/email/confirm` (publique, ne fait rien sur un simple GET), un bouton confirme : l'adresse change, **toutes les sessions sont fermées** (reconnexion avec la nouvelle adresse) et l'**ancienne adresse reçoit une alerte** (nouvelle adresse masquée, sans lien). Recours si ce n'est pas la bonne personne : un administrateur. La migration `013_create_email_changes_table.sql` est appliquée par `bin/deploy.sh`.

## Délivrabilité des e-mails (#132)

Les e-mails sortent par `sendmail://default` (Exim de l'hébergeur), expéditeur fixe `no-reply@<domaine>`. État constaté lors d'un test réel vers Gmail (en-têtes « Afficher l'original ») :

| Contrôle | Enregistrement DNS (zone du domaine d'envoi) | Résultat |
|---|---|---|
| **DKIM** | clé publiée au sélecteur `default` (activée côté cPanel) | PASS |
| **DMARC** | `_dmarc.<domaine>` TXT `v=DMARC1; p=none` | PASS (aligné par DKIM) |
| **SPF** | TXT `v=spf1 +mx +a +ip4:<IP du serveur> ~all` | **SOFTFAIL** |

Boîte de réception chez Gmail (c'était le spam avant l'ajout de DMARC). **SPF reste en softfail par construction :** l'IP de sortie des e-mails n'est pas celle du serveur web et **change d'un envoi à l'autre** (groupe d'IP de l'hébergeur), donc lister une IP dans le SPF ne tient pas. DKIM + DMARC suffisent à l'alignement. Ne **pas** autoriser toute une plage d'IP de l'hébergeur : d'autres clients pourraient alors envoyer en notre nom avec un SPF valide. Durcissement possible : demander au support de l'hébergeur son `include:` SPF officiel pour l'envoi sortant.

Vérifier après une modification DNS ou un changement d'hébergeur :
1. `dig +short TXT _dmarc.<domaine>` et `dig +short TXT <domaine>` (un seul enregistrement SPF).
2. Demander une réinitialisation vers une adresse Gmail de contrôle, puis ⋮ → « Afficher l'original » : DKIM et DMARC doivent être **PASS**. Ne jamais partager le corps du message (lien de connexion valable 1 heure) ; la limite est de 3 demandes par heure et par compte.

## Cache des assets (#150)

`public/.htaccess` envoie `Cache-Control: no-cache` sur `*.css`, `*.js` et `*.mjs` : le navigateur revalide à chaque chargement (`Last-Modified` change à chaque release : 200 avec le nouveau fichier, sinon 304). Pas de `?v=` : `app.js` importe 13 modules par chemin relatif, que l'on ne peut pas versionner un par un sans build. Vérification après déploiement : `curl -sI https://<domaine>/assets/js/app.js | grep -i cache-control` doit afficher `no-cache`. Un navigateur qui avait déjà mis un fichier en cache **avant** ce réglage le garde jusqu'à l'expiration de sa fraîcheur heuristique (rechargement forcé une fois).

## Points d'attention o2switch

- Pas de démon, pas de process > 420 s CPU, < 20 000 fichiers par dossier.
- **PATCH et DELETE** : les CGV ne citent que POST/GET/OPTIONS/PUT → à tester après chaque changement d'hébergement (`curl -X PATCH` / `-X DELETE` sur une route authentifiée doit renvoyer du JSON applicatif, pas une 405/501).
- E-mail : `sendmail://default` (Exim local), expéditeur fixe, l'utilisateur est en `Reply-To`. 20 000 envois automatisés/jour max.
- `/usr/local/bin/php` et le PHP web peuvent différer : vérifier les deux après un changement de version.
- Client SQL : `mariadb` / `mariadb-dump` (les alias `mysql*` sont dépréciés).

## Diagnostic

| Symptôme | Piste |
|---|---|
| 404/403 sur tout le site | racine du document ≠ `<dossier-domaine>/public`, ou lien `public` absent |
| 500 | `~/logs`, droits de `shared/config.local.php`, version PHP web |
| Accès DB refusé | variables `PROD_DB_*`, `RB_REGEN_CONFIG=1 ./bin/deploy.sh` |
| `composer` affiche son aide et sort en 0 | PHP CGI utilisé : toujours `php composer` avec le PHP CLI explicite |
| Ancien design/routes 404 après un déploiement | OPcache (voir ci-dessus) : le contrôle `X-Release` doit l'avoir signalé |
| SSH refusé | IPv4 non autorisée dans le cPanel, clé non « Authorize » |

## Critère de bon fonctionnement

Un musicien peut se connecter depuis son téléphone, voir le dashboard des disponibilités, et revendiquer un créneau libéré sans erreur.

## Vérifier que le mot de passe oublié est à temps constant (#219)

Après un déploiement, et à chaque changement d'hébergeur ou de version de PHP : la réponse de `POST /api/auth/forgot-password` doit avoir **le même temps** pour une adresse connue et pour une adresse inconnue (le travail suit la réponse grâce à `litespeed_finish_request` / `fastcgi_finish_request`). Mesurer ~10 requêtes de chaque sorte (avec un compte de test, jamais une vraie adresse affichée) : les médianes doivent être du même ordre (quelques ms). Si l'écart dépasse ~50 ms, la fonction de libération du client n'est pas disponible : le signaler avant de se fier à la protection.
