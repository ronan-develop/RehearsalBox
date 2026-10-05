<?php
/**
 * @var string $counterpartName groupe qui attend une réponse
 * @var string $link            lien vers la conversation (connexion requise)
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Un message attend une réponse</h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;">Le groupe <strong><?= e($counterpartName) ?></strong> a écrit à votre groupe sur RehearsalBox, et le message n'a pas encore été lu depuis plus de 24 heures.</p>
<p style="margin:0 0 24px 0;">Pour le lire et répondre, ouvrez la conversation avec le bouton ci-dessous (connexion requise).</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#b5654a;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Ouvrir la conversation</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 8px 0;font-size:13px;color:#5a5449;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>
<p style="margin:0 0 24px 0;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#9c5540;"><?= e($link) ?></a></p>
<p style="margin:0;font-size:14px;color:#5a5449;">Il suffit qu'une personne de votre groupe ouvre la conversation pour que ce rappel ne se répète pas. Le contenu du message n'est volontairement pas envoyé par e-mail.</p>
