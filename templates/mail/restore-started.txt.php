<?php
/**
 * @var string $file sauvegarde choisie
 * @var string $when date et heure locales de la demande
 */
?>
Bonjour,

Une restauration de la base de données de RehearsalBox vient d'être lancée depuis votre compte.

Sauvegarde choisie : <?= $file ?>

Demande faite le <?= $when ?>.

Si c'est vous, il n'y a rien à faire : un dump de l'état précédent est conservé automatiquement avant la restauration.

Si vous n'êtes PAS à l'origine de cette demande, votre compte est peut-être compromis : contactez immédiatement un administrateur de RehearsalBox, changez votre mot de passe et désactivez la restauration par le web (réglage restore.enabled du serveur).
