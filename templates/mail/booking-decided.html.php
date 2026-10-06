<?php
/**
 * Issue d'une réservation libre (#263), envoyée à la personne qui l'a demandée : validée ou refusée, avec le motif facultatif de
 * l'administrateur. Aucune adresse ; le motif est échappé.
 *
 * @var string      $groupName
 * @var string      $when     jour lisible
 * @var string      $range    plage lisible
 * @var bool        $accepted vrai : validée ; faux : refusée
 * @var string|null $note     motif de refus donné par l'administrateur, s'il y en a un
 * @var string      $link     lien vers le planning (connexion requise)
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;"><?= $accepted ? 'Votre réservation est validée' : 'Votre réservation est refusée' ?></h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;">La réservation du local pour le groupe <strong><?= e($groupName) ?></strong> a été <strong><?= $accepted ? 'validée' : 'refusée' ?></strong> :</p>
<p style="margin:0 0 24px 0;font-size:18px;"><strong><?= e($when) ?></strong><br><?= e($range) ?></p>
<?php if (!$accepted && $note !== null && $note !== ''): ?>
<p style="margin:0 0 24px 0;padding:12px 16px;background:#f3eee6;border-left:4px solid #b5654a;">Motif : <?= e($note) ?></p>
<?php endif; ?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#b5654a;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Ouvrir le planning</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 8px 0;font-size:13px;color:#5a5449;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>
<p style="margin:0;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#9c5540;"><?= e($link) ?></a></p>
