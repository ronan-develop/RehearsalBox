# CI/CD

## Pipeline

|Job|Déclencheur|Détail|
|-|-|-|
|PHPUnit + MariaDB|push / PR sur `main`|PHP 8.4, MariaDB 10.11, `composer audit` avant les tests|
|Tests JS|push / PR sur `main`|Node.js 22, `node --test` sur `assets/js/*`|

Défini dans [`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

La CI a démarré rouge dès le premier commit (aucun code ni test à ce stade) — c'est un choix assumé (RED avant GREEN), pas une anomalie. Elle passe au vert au fur et à mesure que `composer.json`/PHPUnit (étape 1) puis les premiers tests JS (étape 4) arrivent.

## Déploiement

**Manuel, pas automatique** — hébergeur pas encore choisi, voir `.claude/deploiement.md` (WIP). Un déploiement ne part jamais avec des tests rouges en local (`./vendor/bin/phpunit` + `npm test` avant tout push vers `main`).

## Suivi d'avancement

Mettre à jour `.github/avancement.md` après chaque tâche complétée (fichier à créer au premier besoin).
