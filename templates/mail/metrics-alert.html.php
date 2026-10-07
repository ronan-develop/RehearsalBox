<?php
/**
 * @var list<string> $messages une phrase par alerte (chiffres seulement)
 * @var string $link tableau de bord des mesures
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">Alerte de mesures</h1>
<p style="margin:0 0 16px 0;">Un seuil critique a été franchi :</p>
<ul style="margin:0 0 24px 0;padding-left:20px;">
    <?php foreach ($messages as $message): ?>
        <li style="margin:0 0 8px 0;"><?= e($message) ?></li>
    <?php endforeach; ?>
</ul>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#9c5540;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Voir le tableau de bord</a>
        </td>
    </tr>
</table>
<p style="margin:0;font-size:13px;color:#5a5449;">Une alerte de même type n'est pas renvoyée avant plusieurs heures. Ce message ne contient aucune donnée personnelle.</p>
