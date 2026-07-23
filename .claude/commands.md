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
