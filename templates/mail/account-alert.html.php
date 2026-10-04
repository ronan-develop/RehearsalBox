<?php
/** @var string $link lien « Ce n'est pas moi » (valable 24 heures, à usage unique) */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Votre mot de passe a été modifié</h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;">Le mot de passe de votre compte RehearsalBox vient d'être modifié. Si c'est vous, il n'y a rien à faire.</p>
<p style="margin:0 0 24px 0;"><strong>Si vous n'êtes PAS à l'origine de ce changement</strong>, sécurisez votre compte maintenant (lien valable 24 heures, à usage unique).</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#a8493c;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Ce n'est pas moi : sécuriser mon compte</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 8px 0;font-size:13px;color:#5a5449;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>
<p style="margin:0 0 24px 0;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#9c5540;"><?= e($link) ?></a></p>
<p style="margin:0;font-size:14px;color:#5a5449;">Le compte sera verrouillé, toutes les sessions seront fermées et un lien vous sera envoyé pour choisir un nouveau mot de passe.</p>
