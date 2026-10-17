<?php
/**
 * Copie au président (#362) quand le retard dure : même règle de discrétion, aucun montant, aucun nom.
 *
 * @var int $count nombre de tâches en retard
 * @var int $days  ancienneté, en jours, du plus vieux retard
 * @var string $link lien vers la comptabilité
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Comptabilité : retard de plus de <?= e((string) $days) ?> jours</h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;">La comptabilité de l'association a <?= e((string) $count) ?> tâche<?= $count > 1 ? 's' : '' ?> en retard, dont la plus ancienne depuis plus de <?= e((string) $days) ?> jours. Le trésorier a déjà été prévenu.</p>
<p style="margin:0 0 24px 0;">Vous pouvez consulter la liste « À faire » avec le bouton ci-dessous (connexion requise).</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#b5654a;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Ouvrir la comptabilité</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 8px 0;font-size:13px;color:#5a5449;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>
<p style="margin:0 0 24px 0;font-size:13px;word-break:break-all;"><a href="<?= e($link) ?>" style="color:#9c5540;"><?= e($link) ?></a></p>
<p style="margin:0;font-size:13px;color:#5a5449;">Ce message part au plus une fois par semaine et s'arrête dès que les tâches sont faites. Aucun montant ni aucun nom n'est envoyé par e-mail.</p>
