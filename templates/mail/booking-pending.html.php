<?php
/**
 * Alerte aux administrateurs (#263) : une réservation libre attend leur décision. Non désactivable : aucun lien de désabonnement.
 * Ni le motif du demandeur ni aucune adresse : seulement le groupe, le jour, la plage et le lien vers l'écran de validation.
 *
 * @var string $groupName groupe qui demande
 * @var string $when      jour lisible (« mercredi 7 octobre 2026 »)
 * @var string $range     plage lisible (« 09:00 – 14:00 »)
 * @var string $link      lien vers l'écran de validation (connexion requise)
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Une réservation attend votre décision</h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;">Le groupe <strong><?= e($groupName) ?></strong> demande à réserver le local :</p>
<p style="margin:0 0 24px 0;font-size:18px;"><strong><?= e($when) ?></strong><br><?= e($range) ?></p>
<p style="margin:0 0 24px 0;">Vous pouvez valider ou refuser cette réservation depuis l'écran d'administration (connexion requise). Le premier administrateur qui répond tranche.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#b5654a;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Voir les réservations à valider</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 8px 0;font-size:13px;color:#5a5449;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>
<p style="margin:0;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#9c5540;"><?= e($link) ?></a></p>
