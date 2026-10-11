<?php
/**
 * @var string $link       page de connexion
 * @var string $forgotLink page « Mot de passe oublié » (activation du compte)
 */
?>
<h1 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#1c1a17;">L'appli #B27 est ouverte !</h1>
<p style="margin:0 0 16px 0;">Bonjour,</p>
<p style="margin:0 0 24px 0;">L'appli #B27 est maintenant disponible pour tous les groupes du local. Son but : <strong>voir à l'avance si le local est libre</strong>, sans avoir à demander à tout le monde.</p>

<h2 style="margin:0 0 8px 0;font-size:17px;color:#1c1a17;">Se connecter</h2>
<p style="margin:0 0 16px 0;"><strong>Première fois ?</strong> Votre compte existe déjà : pour l'activer, passez par « Mot de passe oublié » avec votre adresse e-mail et choisissez votre mot de passe.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#b5654a;border-radius:4px;">
            <a href="<?= e($forgotLink) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Activer mon compte</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 16px 0;"><strong>Compte déjà configuré ?</strong> Rien à refaire, connectez-vous comme d'habitude.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
    <tr>
        <td style="background:#b5654a;border-radius:4px;">
            <a href="<?= e($link) ?>" style="display:inline-block;padding:14px 24px;font-weight:bold;font-size:16px;color:#ffffff;text-decoration:none;">Me connecter</a>
        </td>
    </tr>
</table>
<h2 style="margin:0 0 8px 0;font-size:17px;color:#1c1a17;">À quoi ça sert</h2>
<p style="margin:0 0 8px 0;"><strong>1. Voir le planning.</strong> Les créneaux du local apparaissent dans l'appli : vous voyez d'un coup d'œil ce qui est pris et ce qui est libre.</p>
<p style="margin:0 0 8px 0;"><strong>2. Réserver un créneau libre.</strong> Un créneau disponible se réserve directement depuis l'appli, et votre réservation y apparaît aussitôt.</p>
<p style="margin:0 0 8px 0;"><strong>3. Demander un échange de créneau.</strong> Un créneau vous intéresse mais il est pris ? Contactez le groupe concerné depuis l'appli : la demande d'échange lui apparaît dans l'appli.</p>
<p style="margin:0;"><strong>4. Écrire aux autres membres.</strong> La messagerie permet d'échanger entre membres directement dans l'appli.</p>

