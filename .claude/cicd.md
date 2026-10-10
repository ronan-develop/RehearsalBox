# CI/CD

## Pipeline

|Job|Déclencheur|Détail|
|-|-|-|
|PHPUnit + MariaDB|push / PR sur `main`|PHP 8.4, MariaDB 10.11 ; avant les tests : `composer audit`, PHPStan (niveau 6), style PSR-12 (PHP-CS-Fixer), budget de taille des classes (`bin/check-size.php`), plafond de classes par dossier (`bin/check-folders.php`, #287) ; PHPUnit tourne avec **PCOV** et publie un résumé de couverture par dossier et par couche (`bin/coverage-summary.php`, #129) ; **bloquant sous 90 % de couverture totale** (`--min=90`, #365 ; le seuil n'est écrit que dans `.github/workflows/ci.yml`, pas de seuil par dossier au départ : View, Http, Controller et Security racine sont sous 90 %)|
|Tests JS|push / PR sur `main`|Node.js 22, `npm audit`, `node --test` sur `assets/js/*`|

Défini dans [`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

La CI a démarré rouge dès le premier commit (aucun code ni test à ce stade) — c'est un choix assumé (RED avant GREEN), pas une anomalie. Elle passe au vert au fur et à mesure que `composer.json`/PHPUnit (étape 1) puis les premiers tests JS (étape 4) arrivent.

## Déploiement

**Manuel, pas automatique** — `./bin/deploy.sh` depuis le poste de dev (o2switch, SSH), voir `.claude/deploiement.md`. Un déploiement ne part jamais avec des tests rouges en local (`./vendor/bin/phpunit` + `npm test` avant tout push vers `main`).

## Suivi d'avancement

Mettre à jour `.github/avancement.md` après chaque tâche complétée (fichier à créer au premier besoin).
