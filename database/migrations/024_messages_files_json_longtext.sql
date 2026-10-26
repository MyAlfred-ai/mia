-- messages.files_json contient le JSON des pièces jointes du message,
-- y compris le base64 binaire des images (jusqu'à 20 Mo).
-- TEXT (65 ko) est trop petit : un screenshot suffit à dépasser
-- → l'INSERT échoue en mode strict (PDOException) ou tronque silencieusement
-- → le client reste bloqué sur le placeholder "Analyse de votre demande...".
-- LONGTEXT couvre largement le besoin (4 Go max).

ALTER TABLE messages MODIFY COLUMN files_json LONGTEXT DEFAULT NULL;
