-- Titres chiffrés (#171) : la valeur stockée est `v1.<clé>:<base64(nonce + titre chiffré)>`. Un titre de 150 caractères de 4 octets
-- (600 octets) donne environ 870 caractères une fois chiffré et encodé : la colonne ne peut plus rester à 150. La longueur du titre
-- reste limitée à 150 caractères par l'application (ConversationInputPolicy) ; seule la place du chiffré change.
ALTER TABLE conversations MODIFY title VARCHAR(1024) NULL;
