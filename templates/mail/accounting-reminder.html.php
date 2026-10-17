<?php
/**
 * Rappel au trésorier (#362) : aucun montant, aucun nom, aucune donnée bancaire, seulement un nombre de tâches et un lien (connexion requise).
 *
 * @var int $count nombre de tâches en retard
 * @var string $link lien vers la comptabilité
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Comptabilité : <?= e((string) $count) ?> tâche<?= $count > 1 ? 's' : '' ?> en retard</h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 16px 0;">Il reste <?= e((string) $count) ?> tâche<?= $count > 1 ? 's' : '' ?> de comptabilité en retard : relevés à déposer, mouvements à saisir ou exercice à clôturer.</p>
<p style="margin:0 0 24px 0;">Ouvrez la comptabilité avec le bouton ci-dessous (connexion requise) : la liste « À faire » vous dit quoi faire, dans l'ordre.</p>
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
