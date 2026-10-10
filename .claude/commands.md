# Commandes utiles

## Serveur de dev

```bash
php -S localhost:8000 -t public public/index.php
```

## Base de données

```bash
php bin/migrate.php              # applique les migrations non jouées (table migrations_log)
php database/seed.php --force-local   # jeu de démo (EFFACE la base de développement ; refusé hors local, jamais livré en production)
mysql -u root < database/schema.sql   # (re)création complète du schéma en local
```

### Deux bases Docker distinctes — dev et test, à ne pas confondre

L'accès `mysql -u root` local échoue en général sur une install MariaDB système (auth `unix_socket`, pas de mot de passe simple). Plutôt que de reconfigurer l'install système, **deux** conteneurs Docker isolés existent — **dev/test uniquement, pas représentatif de la prod (mutualisé, sans Docker)** :

| Environnement | Compose file               | Container               | Port hôte | Base                 | Utilisée par                                              |
|---------------|-----------------------------|--------------------------|-----------|-----------------------|-------------------------------------------------------------|
| Dev           | `docker-compose.dev.yml`   | `rehearsalbox-dev-db`   | `3308`    | `rehearsalbox_dev`  | `php -S` (serveur de dev, via `config/config.local.php`) |
| Test          | `docker-compose.test.yml`  | `rehearsalbox-test-db`  | `3307`    | `rehearsalbox_test` | PHPUnit (via `tests/TestDatabase.php`)                    |

**Avant tout run PHPUnit**, s'assurer que le conteneur *test* (port 3307) est démarré — pas seulement le dev. Symptôme si on l'oublie : `phpunit` reste bloqué indéfiniment sans sortie ni erreur (connexion TCP qui n'aboutit jamais sur un port fermé, plutôt qu'un rejet immédiat).

```bash
# Dev (nécessaire pour lancer le serveur de dev / config.local.php)
docker compose -f docker-compose.dev.yml up -d
mysql -h 127.0.0.1 -P 3308 -u root -proot rehearsalbox_dev

# Test (nécessaire pour ./vendor/bin/phpunit)
docker compose -f docker-compose.test.yml up -d
docker compose -f docker-compose.test.yml exec db-test mysqladmin ping -proot   # attendre "mysqld is alive"
mysql -h 127.0.0.1 -P 3307 -u root -proot rehearsalbox_test
```

Config de test attendue par PHPUnit (`tests/TestDatabase.php`) : hôte `127.0.0.1`, port `3307`, user `root`, mot de passe `root`, base `rehearsalbox_test` (surchargeable via `DB_TEST_HOST`/`DB_TEST_PORT`/`DB_TEST_NAME`/`DB_TEST_USER`/`DB_TEST_PASSWORD`).

Config de dev attendue par `config/config.local.php` (gitignored) : host `127.0.0.1`, port `3308`, base `rehearsalbox_dev`, user `root`, password `root`.

Les deux peuvent tourner en même temps (ports différents) — `docker compose ... up -d` sans argument supplémentaire ne démarre que le fichier compose ciblé, pas les deux.

Arrêter/nettoyer : `docker compose -f docker-compose.test.yml down -v` (idem avec `.dev.yml`).

## Dépendances

```bash
composer install
composer audit                   # avant tout déploiement
```

## Tests

```bash
./vendor/bin/phpunit --colors=always
composer test:quick   # boucle de travail (#301) : sans les tests qui touchent la base (≈ 3 s, sans conteneur) ; la suite complète reste obligatoire avant la PR
./vendor/bin/phpunit --filter NomDuTest
npm test                         # tests JS (node --test sur assets/js/*)
```

### Couverture de tests (#129, PCOV)

La CI mesure la couverture à chaque run (extension **PCOV**, légère) et affiche le résumé par dossier et par couche dans l'onglet « Summary » du run. **Aucun seuil bloquant** : on mesure d'abord. En local, PCOV est facultatif (non installé par défaut : le run est plus lent avec) :

```bash
# Installation (une fois) : paquet de la distribution, ex. Debian/Ubuntu → sudo apt install php8.4-pcov  (ou : pecl install pcov)
php -d pcov.enabled=1 ./vendor/bin/phpunit --coverage-clover=build/coverage/clover.xml   # conteneur de test démarré, comme pour tout PHPUnit
php bin/coverage-summary.php                                                              # Markdown : le moins couvert d'abord (build/ est ignoré par git)
```

Couverture JS : `node --test --experimental-test-coverage` (hors périmètre pour l'instant).

## Contrôles de qualité (aussi exécutés par la CI)

```bash
./vendor/bin/phpstan analyse --memory-limit=512M   # analyse statique, niveau 6 (phpstan.neon.dist)
./vendor/bin/php-cs-fixer fix --dry-run --diff     # style PSR-12 ; sans --dry-run, corrige
php bin/check-folders.php                          # plafond de 12 classes par dossier de src/ (#287), exceptions figées dans config/folder-budget-exceptions.php
php bin/check-size.php                             # budget de taille : 250 lignes et 10 méthodes publiques par classe
```

Le budget de taille a un **cliquet** : les classes déjà trop grosses sont listées dans `config/size-budget-exceptions.php` à leur taille actuelle, elles ne peuvent plus grossir et leur entrée doit être retirée dès qu'elles repassent sous le budget (le contrôle l'exige). PHPUnit échoue aussi sur un test risqué, un avertissement, une dépréciation ou une notice.

```bash
php bin/tail-log.php app 50    # 50 dernières lignes du journal applicatif (idem : cron)
```
