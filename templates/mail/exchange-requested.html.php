<?php
/**
 * Demande d'échange de créneau (#455), envoyée à l'adresse de contact du groupe titulaire. Le motif saisi par le groupe demandeur n'est
 * jamais repris ; tout est échappé.
 *
 * @var string $requesterGroup groupe qui demande
 * @var string $holderGroup    groupe titulaire du créneau
 * @var string $when           jour lisible
 * @var string $range          plage lisible
 * @var string $link           lien vers la page de la demande (connexion requise)
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Une demande d'échange de créneau</h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;">Le groupe <strong><?= e($requesterGroup) ?></strong> demande à échanger le créneau de <strong><?= e($holderGroup) ?></strong> :</p>
<p style="margin:0 0 24px 0;font-size:18px;"><strong><?= e($when) ?></strong><br><?= e($range) ?></p>
<p style="margin:0 0 24px 0;">Vous pouvez l'accepter ou la refuser en un geste, depuis la page de la demande (connexion requise).</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#b5654a;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Voir et répondre à la demande</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 8px 0;font-size:13px;color:#5a5449;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>
<p style="margin:0;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#9c5540;"><?= e($link) ?></a></p>
