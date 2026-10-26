<?php $accentColor = Settings::accentColor(); ?>
<?php /* --accent-base : la couleur de l'organisation telle quelle. Le contraste
         renforcé (Réglages → Affichage) redéfinit --accent À PARTIR d'elle — une
         propriété ne peut pas se lire elle-même. */ ?>
<style>:root { --accent: <?= htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') ?>; --accent-base: <?= htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') ?>; }</style>
