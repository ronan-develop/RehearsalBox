# Commandes utiles

## Serveur de dev

```bash
php -S localhost:8000 -t public public/index.php
```

## Base de données

```bash
php bin/migrate.php              # applique les migrations non jouées (table migrations_log)
mysql -u root < database/schema.sql   # (re)création complète du schéma en local
```

### DB de test locale — MariaDB via Docker

L'accès `mysql -u root` local échoue en général sur une install MariaDB système (auth `unix_socket`, pas de mot de passe simple). Plutôt que de reconfigurer l'install système, une MariaDB de test tourne dans un conteneur Docker isolé — **dev/test uniquement, pas représentatif de la prod (mutualisé, sans Docker)**. Config versionnée dans `docker-compose.test.yml`.

```bash
docker compose -f docker-compose.test.yml up -d
docker compose -f docker-compose.test.yml exec db-test mysqladmin ping -proot   # attendre "mysqld is alive"

# connexion depuis l'hôte (port 3307, pas le 3306 par défaut) :
mysql -h 127.0.0.1 -P 3307 -u root -proot rehearsalbox_test
```

Config de test attendue par les tests PHPUnit (`tests/`) : hôte `127.0.0.1`, port `3307`, user `root`, mot de passe `root`, base `rehearsalbox_test`.

Arrêter/nettoyer : `docker compose -f docker-compose.test.yml down -v`.

## Dépendances

```bash
composer install
composer audit                   # avant tout déploiement
```

## Tests

```bash
./vendor/bin/phpunit --colors=always
./vendor/bin/phpunit --filter NomDuTest
npm test                         # tests JS (node --test sur assets/js/*)
```
