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
└── backups/pre-<release>.sql.gz   ← dump avant migration (7 conservés)
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
./bin/deploy.sh          # phpunit + npm test + composer audit, puis envoi, install, sauvegarde, migrations, bascule
```

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

`bin/send-reminders.php` envoie, **en deux temps** (relances de groupe puis relances de mention, deux lignes de bilan), à l'adresse de contact d'un groupe **une relance** quand un message de l'autre côté est resté **24 h sans lecture** par ce groupe (jamais le contenu du message), seulement entre 9 h et 20 h (heure locale `app.timezone`) ; hors plage il ne fait rien et les relances dues partent le matin. Un message de plus de 7 jours n'est plus relancé. La **relance de mention** (#178) part à l'adresse du compte d'une personne taguée qui n'a pas lu la conversation 24 h après l'e-mail de mention (une seule par e-mail de mention, jamais le contenu) ; la même plage de jour s'applique. Le script s'arrête en erreur (code 1) si l'un des deux envois échoue. Idempotent : peut être relancé sans doublon.

Installée **une fois** (entrée `0 * * * *`, par SSH avec `crontab`, ou dans le cPanel *Tâches cron* si l'hébergeur le propose), avec le PHP CLI explicite du déploiement. La commande porte un **garde** : tant que la release active ne contient pas le script, elle ne fait rien (aucune erreur avant le premier déploiement qui l'embarque).

```bash
cd "$HOME/rehearsalbox/current" && [ -f bin/send-reminders.php ] && /usr/local/bin/php bin/send-reminders.php >> "$HOME/rehearsalbox/shared/reminders.log" 2>&1
```

- L'installation est **idempotente** (relancer remplace l'entrée, jamais de doublon) ; l'ancienne table cron est sauvegardée dans `shared/crontab.bak`. Vérification : `crontab -l | grep -c send-reminders` doit valoir 1.
- La commande passe par le lien `current` : elle suit toujours la release active, sans rien changer à chaque déploiement.
- Sortie : une ligne de bilan (`n envoyée(s), n échec(s), n ignorée(s)`), **sans adresse ni contenu** ; code de sortie non nul en cas d'échec d'envoi (réessayé à l'exécution suivante).
- Le journal `reminders.log` est dans `shared/` (hors webroot) ; le purger ou le faire tourner de temps en temps.
- Aucun secret dans la commande : la configuration (transport e-mail, adresse d'expédition, fuseau) vient de `config.local.php`.
- Un e-mail immédiat part aussi à la création d'une conversation (sans cron) ; si le cron n'est pas installé, seules les relances manquent. Les conversations **antérieures** au déploiement des e-mails n'en reçoivent aucun (migration 017).

## Réinitialisation de mot de passe

- Pages publiques `/forgot-password` et `/reset-password?token=…` ; API `POST /api/auth/forgot-password` et `/api/auth/reset-password`.
- Jeton aléatoire de 256 bits, **stocké haché** (SHA-256) dans `password_resets` (migration 010), valable 1 heure, **à usage unique** (`UPDATE … WHERE used_at IS NULL AND expires_at > :now`). Un nouveau jeton annule les précédents ; 3 demandes par heure et par compte au maximum.
- Réponse **identique** que le compte existe ou non (pas d'énumération). Le lien du mail est construit depuis `APP_URL` (`app.base_url`), **jamais** depuis l'en-tête `Host`.
- La page de réinitialisation envoie `Referrer-Policy: no-referrer` et `Cache-Control: no-store` (le jeton est dans l'URL).
- Premier déploiement de la fonctionnalité : `APP_URL` doit être dans le fichier de secrets et la configuration serveur régénérée (`RB_REGEN_CONFIG=1 ./bin/deploy.sh`) ; la migration 010 est appliquée par le déploiement (sauvegarde préalable automatique).
- Limite connue : les sessions déjà ouvertes ne sont pas fermées après un changement de mot de passe (sessions PHP natives).

## Changement de mot de passe, sessions et alerte (#108)

- Page « Mon mot de passe » (`/account/password`, lien « Compte » dans la barre du bas) ; `POST /api/auth/change-password`. L'utilisateur visé est celui de la **session**, jamais un identifiant de la requête. Un mot de passe actuel faux compte comme un échec de connexion (mêmes limites : 5 échecs, verrouillage 15 min).
- **Sessions versionnées** : `users.session_version` (migration 011), copiée dans la session à la connexion et comparée à chaque requête. Un changement de mot de passe (ou « Ce n'est pas moi ») incrémente la version : les autres appareils sont déconnectés à leur prochaine requête, l'appareil courant reste connecté (session régénérée). Les sessions ouvertes avant la migration valent 0 : personne n'est déconnecté au déploiement.
- **Alerte** envoyée après un changement, avec un lien « Ce n'est pas moi » (jeton de 24 h, à usage unique, stocké haché dans `password_resets` avec `purpose = 'alert'`, migration 012). Le lien ouvre une page de **confirmation** (`/account/secure`) ; l'action n'a lieu qu'au `POST /api/auth/secure-account`, jamais sur un GET.
- « Ce n'est pas moi » **verrouille** le compte (7 jours), ferme toutes les sessions et envoie un lien de réinitialisation. L'ancien mot de passe n'est **pas** restauré : l'auteur du changement le connaît forcément.
- Déploiement : les migrations 011 et 012 sont appliquées automatiquement (sauvegarde préalable).

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
