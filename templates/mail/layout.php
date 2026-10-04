<?php
/**
 * Gabarit commun des e-mails (#157) : tables et styles en ligne (compatibilité des
 * clients de messagerie), aucune police web ni image distante. Reprend le design du site :
 * fond sombre, carte « papier » claire, accent terracotta.
 *
 * @var string $content   corps HTML déjà échappé par le gabarit de l'e-mail
 * @var string $preheader texte d'aperçu (facultatif)
 */
$font = "Arial, 'Helvetica Neue', Helvetica, sans-serif";
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>RehearsalBox</title>
</head>
<body style="margin:0;padding:0;background:#15151a;">
<?php if ($preheader !== ''): ?>
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#15151a;"><?= e($preheader) ?></div>
<?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#15151a;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
                <tr>
                    <td align="center" style="padding:0 0 16px 0;font-family:<?= $font ?>;">
                        <?php /* Logo du site (#B27, police A Dripping Marker) en image intégrée au mail : cid:logo-b27, voir MailRenderer::compose() */ ?>
                        <img src="cid:logo-b27" alt="#B27 RehearsalBox" width="200" height="102" style="display:block;margin:0 auto;border:0;outline:none;text-decoration:none;color:#b5654a;font-size:30px;font-weight:bold;">
                        <div style="margin:2px 0 0 0;font-size:11px;letter-spacing:0.04em;text-transform:uppercase;color:#a8a6a2;">Local</div>
                    </td>
                </tr>
                <tr>
                    <td style="background:#ece9e2;border-radius:8px;padding:32px 28px;font-family:<?= $font ?>;font-size:16px;line-height:1.5;color:#1c1a17;">
                        <?= $content ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 4px 0 4px;font-family:<?= $font ?>;font-size:12px;line-height:1.5;color:#726f6b;">
                        RehearsalBox — gestion des répétitions.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
