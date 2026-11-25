<?php
/**
 * Points à améliorer d'une base (KbAdvice::forBase) — SEUL rendu : posé au
 * chargement de la fiche ET renvoyé tout fait par …/sync-status, que la
 * fiche remplace sans connaître un libellé (comme kb-status.php).
 *
 * Attend : $kbAdvice (liste de KbAdvice::forBase). Rien si la liste est vide.
 */
$kbAdvice = $kbAdvice ?? [];
?>
<?php if ($kbAdvice !== []): ?>
<ul class="kbf-advice-issues">
    <?php foreach ($kbAdvice as $advice): ?>
        <li class="kbf-advice-issue" data-key="<?= htmlspecialchars((string)$advice['key']) ?>">
            <span class="kbf-advice-dot" aria-hidden="true"></span>
            <div class="kbf-advice-issue-text">
                <strong><?= htmlspecialchars((string)$advice['title']) ?></strong>
                <span><?= htmlspecialchars((string)$advice['text']) ?></span>
                <?php if (!empty($advice['docs'])): ?>
                    <span class="kbf-advice-docs"><?= htmlspecialchars(implode(' · ', $advice['docs'])) ?></span>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
