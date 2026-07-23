# Méthodologie TDD — OBLIGATOIRE

## Cycle

1. **RED** — écrire le test (il échoue)
2. **GREEN** — implémenter le minimum pour le faire passer
3. **REFACTOR** — nettoyer sans casser les tests

Ne jamais passer à GREEN sans avoir vu le test échouer en RED.

## Stack

- **PHPUnit** nu (pas de framework, donc pas de `symfony/test-pack`/`ApiTestCase`)
- Tests des `Api\*Controller` : instancier un `Request` à la main ou taper l'endpoint via le serveur PHP built-in dans un `ApiTestCase` maison léger si le besoin s'en fait sentir
- **`node --test`** pour `assets/js/*` (pas de bundler, pas besoin de Jest complet)

## Ce qui ne se teste pas au sens RED/GREEN

Classes purement structurelles sans branche logique (`Http/Request` value object, `Entity/*` sans comportement, templates PHP de vue) — mais tout ce qui les consomme (un `Service` qui les manipule) est testé.

## Couverture attendue par feature

- Accès non autorisé (ownership check)
- Cas nominal (happy path)
- Cas limites (validation, conflit de noms, données manquantes)
- Rollback / erreur (ex: échec de stockage physique)
