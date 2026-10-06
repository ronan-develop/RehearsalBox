-- Supprimer un groupe (#224) échouait (erreur 500) s'il avait fait des demandes de créneau : la clé étrangère
-- `requested_by_group_id` n'avait pas de ON DELETE, contrairement à toutes les autres relations du groupe (membres,
-- créneaux, documents, conversations). Les demandes d'un groupe supprimé n'ont plus de sens : elles partent avec lui.
-- Deux instructions : MariaDB refuse de supprimer et recréer une contrainte du même nom dans un seul ALTER.
ALTER TABLE slot_exceptions DROP FOREIGN KEY fk_slot_exceptions_requested_group;
ALTER TABLE slot_exceptions ADD CONSTRAINT fk_slot_exceptions_requested_group FOREIGN KEY (requested_by_group_id) REFERENCES `groups`(id) ON DELETE CASCADE;
