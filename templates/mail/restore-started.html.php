<?php
/**
 * @var string $file sauvegarde choisie
 * @var string $when date et heure locales de la demande
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Une restauration de la base a été lancée</h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;">Une restauration de la base de données de RehearsalBox vient d'être lancée depuis votre compte.</p>
<p style="margin:0 0 16px 0;">Sauvegarde choisie : <strong><?= e($file) ?></strong><br>Demande faite le <?= e($when) ?>.</p>
<p style="margin:0 0 16px 0;">Si c'est vous, il n'y a rien à faire : un dump de l'état précédent est conservé automatiquement avant la restauration.</p>
<p style="margin:0;"><strong>Si vous n'êtes PAS à l'origine de cette demande</strong>, votre compte est peut-être compromis : contactez immédiatement un administrateur de RehearsalBox, changez votre mot de passe et désactivez la restauration par le web (réglage <code>restore.enabled</code> du serveur).</p>
