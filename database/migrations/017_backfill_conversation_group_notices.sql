-- Les conversations existantes avant les e-mails (#180) sont sans objet : ni e-mail immédiat ni relance rétroactifs.
-- Seuls les messages postérieurs à cette migration pourront déclencher une relance.
INSERT INTO conversation_group_notices (conversation_id, group_id, notified_at, reminded_at)
SELECT c.id, g.group_id, NOW(), GREATEST(NOW(), COALESCE((SELECT MAX(m.created_at) FROM conversation_messages m WHERE m.conversation_id = c.id), NOW()))
FROM conversations c
JOIN (
    SELECT id AS conversation_id, initiator_group_id AS group_id FROM conversations
    UNION
    SELECT id, target_group_id FROM conversations
) g ON g.conversation_id = c.id
ON DUPLICATE KEY UPDATE
    notified_at = COALESCE(conversation_group_notices.notified_at, VALUES(notified_at)),
    reminded_at = GREATEST(COALESCE(conversation_group_notices.reminded_at, VALUES(reminded_at)), VALUES(reminded_at));
