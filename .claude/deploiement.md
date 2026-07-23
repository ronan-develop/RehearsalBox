# Déploiement — WIP, hébergeur non choisi

⚠️ **Cette section est un squelette à compléter.** L'hébergeur cible n'est pas encore choisi (probable hébergement mutualisé, cf. contraintes du projet : PHP pur, pas de composer install garanti en prod, upload FTP possible). Ne pas présumer d'un hébergeur précis (o2switch ou autre) tant que la décision n'est pas prise — compléter ce fichier à ce moment-là.

## Contraintes connues à date

- Hébergement mutualisé probable : pas d'accès SSH garanti, pas de process long-running, `vendor/` committé plutôt que `composer install` en prod si l'hébergeur ne le permet pas.
- `public/` doit être l'unique document root exposée ; `src/`, `config/`, `database/` restent hors webroot (cf. `.claude/architecture.md`).
- HTTPS obligatoire, cookie de session `Secure`+`httponly`+`SameSite` (cf. plan de sécurité).

## À définir une fois l'hébergeur choisi

- Méthode de déploiement (SSH manuel via script `bin/`, FTP, ou webhook)
- Variables d'environnement en prod (`config/config.local.php`, jamais committé)
- Prérequis panel d'hébergement (sous-domaine, base MySQL, clé SSH si applicable)
- Diagnostic des erreurs fréquentes (500, accès DB refusé, etc.)

## Critère de bon fonctionnement (à valider après premier déploiement réel)

Un musicien peut se connecter depuis son téléphone, voir le dashboard des disponibilités, et revendiquer un créneau libéré sans erreur.
