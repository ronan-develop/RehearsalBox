-- Citer un message (#214) : une réponse peut citer un message de LA MÊME conversation (vérifié par le service). Seul
-- l'identifiant est stocké : auteur et texte sont relus à l'affichage (un message corrigé apparaît corrigé dans la citation).
ALTER TABLE conversation_messages
    ADD COLUMN reply_to_message_id INT UNSIGNED NULL AFTER is_system,
    ADD CONSTRAINT fk_conversation_messages_reply_to FOREIGN KEY (reply_to_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL;
