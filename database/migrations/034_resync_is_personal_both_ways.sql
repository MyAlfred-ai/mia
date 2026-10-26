-- Resync complet de is_personal d'après le rôle réel du créateur (les deux
-- sens), pour garder le flag cohérent avec la visibilité désormais dérivée du
-- rôle (cf. Profile::accessibleByUser). Le flag ne pilote plus la visibilité
-- mais reste utilisé pour le badge "Personnel" et le quota d'assistants
-- personnels (countPersonalByCreator) — il doit donc rester juste.
--
--   - créateur = "creator"            -> is_personal = 1 (personnel)
--   - créateur admin / autre / NULL   -> is_personal = 0 (organisation)
--
-- Idempotent.

UPDATE profiles p
    LEFT JOIN users u ON u.id = p.created_by
    SET p.is_personal = CASE
        WHEN u.id IS NOT NULL AND u.role = 'creator' THEN 1
        ELSE 0
    END;
