<?php
/**
 * Réponse à une demande d'échange de créneau (#455), envoyée à la personne qui l'a faite : acceptée ou refusée. Aucune adresse ;
 * tout est échappé.
 *
 * @var string $requesterGroup groupe qui avait demandé
 * @var string $holderGroup    groupe titulaire, qui a répondu
 * @var string $when           jour lisible
 * @var string $range          plage lisible
 * @var bool   $accepted       vrai : acceptée ; faux : refusée
 * @var string $link           lien vers l'appli (connexion requise)
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;"><?= $accepted ? 'Votre demande d\'échange est acceptée' : 'Votre demande d\'échange est refusée' ?></h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;">Le groupe <strong><?= e($holderGroup) ?></strong> a <strong><?= $accepted ? 'accepté' : 'refusé' ?></strong> la demande de <strong><?= e($requesterGroup) ?></strong> :</p>
<p style="margin:0 0 24px 0;font-size:18px;"><strong><?= e($when) ?></strong><br><?= e($range) ?></p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#b5654a;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Ouvrir le planning</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 8px 0;font-size:13px;color:#5a5449;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>
<p style="margin:0;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#9c5540;"><?= e($link) ?></a></p>
