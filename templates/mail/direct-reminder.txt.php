<?php
/**
 * Relance d'un message direct non lu (#372) : une seule, 24 h après l'e-mail « vous a écrit ».
 *
 * @var string $senderName
 * @var string $link
 */
?>
Bonjour,

<?= $senderName ?> vous a écrit sur RehearsalBox et vous n'avez pas encore lu le message.

Ouvrez la conversation (connexion requise). C'est le seul rappel que vous recevrez pour ce message :
<?= $link ?>


Le contenu du message n'est volontairement pas envoyé par e-mail : il se lit sur le site.

Pour ne plus recevoir d'e-mail pour cette conversation, mettez-la en sourdine depuis la messagerie.
