# Politique de sécurité

## Signaler une vulnérabilité

Ne publiez pas de faille dans une issue ni une pull request. Utilisez le signalement privé de GitHub :
onglet **Security** du dépôt, puis **Report a vulnerability**. Indiquez la page ou la route concernée, les étapes pour
reproduire et l'impact que vous constatez.

Vous recevrez une réponse sous 7 jours. Le correctif est préparé en privé, puis publié ; la personne qui a signalé est
citée si elle le souhaite.

## Versions suivies

Seule la branche `main` (et la version déployée) reçoit les correctifs de sécurité.

## Cadre

Les tests doivent viser une copie locale de l'application avec des données fictives, jamais l'instance en ligne :
pas de scan automatisé, de déni de service ni d'accès à des données de tiers.
