<?php
/** @var string $link lien de confirmation, envoyé à la NOUVELLE adresse (valable 1 heure, à usage unique) */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Confirmez votre nouvelle adresse e-mail</h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;">Votre compte RehearsalBox va utiliser cette adresse e-mail comme identifiant de connexion.</p>
<p style="margin:0 0 24px 0;">Pour confirmer le changement, utilisez le bouton ci-dessous (lien valable 1 heure, à usage unique). Vous serez déconnecté de vos appareils et devrez vous reconnecter avec cette adresse.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#b5654a;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Confirmer ma nouvelle adresse</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 8px 0;font-size:13px;color:#5a5449;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>
<p style="margin:0 0 24px 0;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#9c5540;"><?= e($link) ?></a></p>
<p style="margin:0;font-size:14px;color:#5a5449;">Si vous n'êtes pas à l'origine de cette demande, ignorez simplement ce message : rien ne sera modifié.</p>
