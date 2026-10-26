-- Modèles image Gemini : les ids preview (gemini-3.1-flash-image-preview,
-- gemini-3-pro-image-preview) sont coupés par Google depuis le 25 juin 2026.
-- Remap vers les versions GA (sorties le 28 mai 2026) et remise à zéro du
-- statut de test pour inviter l'admin à re-tester les outils concernés.

UPDATE chat_tools
   SET model = 'gemini-3.1-flash-image',
       last_test_ok = NULL, last_test_message = NULL, last_tested_at = NULL
 WHERE model = 'gemini-3.1-flash-image-preview';

UPDATE chat_tools
   SET model = 'gemini-3-pro-image',
       last_test_ok = NULL, last_test_message = NULL, last_tested_at = NULL
 WHERE model = 'gemini-3-pro-image-preview';
