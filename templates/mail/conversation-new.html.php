<?php
/**
 * @var string $authorName nom de la personne qui écrit
 * @var string $groupName  groupe au nom duquel elle écrit
 * @var string $link       lien vers la conversation (connexion requise)
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Nouvelle conversation</h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;"><strong><?= e($authorName) ?></strong> (<?= e($groupName) ?>) a écrit à votre groupe sur RehearsalBox.</p>
<p style="margin:0 0 24px 0;">Pour lire le message et répondre, ouvrez la conversation avec le bouton ci-dessous (connexion requise).</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#b5654a;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Ouvrir la conversation</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 8px 0;font-size:13px;color:#5a5449;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>
<p style="margin:0 0 24px 0;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#9c5540;"><?= e($link) ?></a></p>
<p style="margin:0;font-size:14px;color:#5a5449;">Le contenu du message n'est volontairement pas envoyé par e-mail : il se lit sur le site.</p>
