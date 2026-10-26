-- Réglages d'affichage par utilisateur (Paramètres → Affichage).
--
-- Même patron que voice_rate / voice_length (075) : User fait des SELECT *,
-- les colonnes remontent dans $user et dans la session sans requête de plus —
-- c'est ce que lit DisplayPreferences::htmlAttributes() sur CHAQUE page.
--
-- ui_theme : '' = celui de l'organisation (Marque & apparence), et le suit
-- quand l'administrateur le change ; 'light' | 'dark' = imposé par la
-- personne ; 'system' = suit le réglage clair/sombre de l'appareil. Défaut ''
-- et non 'dark' : distinguer « n'a jamais choisi » d'un choix explicite.
--
-- ui_scale : taille d'affichage en pourcentage (zoom de toute l'interface),
-- 100 = taille normale.
--
-- ui_contrast : 1 = contraste renforcé (textes, textes secondaires, bordures).
--
-- ui_send_key : touche d'envoi du message dans le chat. '' = jamais choisi
-- (Entrée, le défaut), 'enter' = Entrée, 'mod_enter' = Ctrl/⌘ + Entrée (Entrée
-- va alors à la ligne).
ALTER TABLE users
    ADD COLUMN ui_theme VARCHAR(10) NOT NULL DEFAULT '',
    ADD COLUMN ui_scale SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    ADD COLUMN ui_contrast TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN ui_send_key VARCHAR(10) NOT NULL DEFAULT '';
