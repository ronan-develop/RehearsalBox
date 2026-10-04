<?php
/**
 * @var string $senderEmail adresse de la personne qui écrit (aussi en Reply-To)
 * @var string $message     message saisi par l'utilisateur : toujours échappé
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Nouveau message pour votre groupe</h1>
<p style="margin:0 0 16px 0;">Un musicien vous écrit depuis RehearsalBox : <strong><?= e($senderEmail) ?></strong>.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="border-left:4px solid #b5654a;padding:8px 16px;background:#f5f2ec;font-size:16px;line-height:1.5;color:#1c1a17;"><?= nl2br(e($message), false) ?></td>
    </tr>
</table>
<p style="margin:0;font-size:14px;color:#5a5449;">Pour répondre, répondez simplement à cet e-mail : votre réponse sera envoyée à cette personne.</p>
