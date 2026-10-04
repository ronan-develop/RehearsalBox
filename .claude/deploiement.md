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

Étapes : contrôles locaux → envoi de la release → `composer install --no-dev` avec le PHP CLI **explicite** + vérification `Nothing to install` → génération de `config.local.php` si absent (`RB_REGEN_CONFIG=1` pour forcer) → dump si la base contient des tables → `bin/migrate.php` → rendu à blanc de `GET /login` en CLI → bascule de `current`. Si une étape échoue, `current` n'est pas modifié. **Jamais** `database/seed.php` en production.

## Comptes initiaux (sans fixtures)

```bash
RB_USER_PASSWORD='<mot-de-passe>' php bin/create-user.php <email> <nom> <admin|musicien>
```

Le mot de passe passe par l'environnement (jamais en argument). La connexion se fait avec l'**e-mail**. Les groupes sont créés ensuite depuis l'interface admin. Changer les mots de passe provisoires dès la première connexion.

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
| SSH refusé | IPv4 non autorisée dans le cPanel, clé non « Authorize » |

## Critère de bon fonctionnement

Un musicien peut se connecter depuis son téléphone, voir le dashboard des disponibilités, et revendiquer un créneau libéré sans erreur.
