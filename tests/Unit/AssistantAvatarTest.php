<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Validation des noms de fichiers d'avatars : la sécurité de l'upload et de la
 * réconciliation disque ↔ base repose sur isValidFilename (anti-traversal,
 * extensions autorisées).
 */
final class AssistantAvatarTest extends TestCase
{
    public function testIsValidFilenameAcceptsAllowedExtensions(): void
    {
        $this->assertTrue(AssistantAvatar::isValidFilename('assistant-20240101-ab12cd34.png'));
        $this->assertTrue(AssistantAvatar::isValidFilename('alfred-robot.png'));
        $this->assertTrue(AssistantAvatar::isValidFilename('logo.jpeg'));
        $this->assertTrue(AssistantAvatar::isValidFilename('mon-avatar_2.webp'));
    }

    public function testIsValidFilenameRejectsTraversalSpacesAndBadExtensions(): void
    {
        $this->assertFalse(AssistantAvatar::isValidFilename('../secret.png'));
        $this->assertFalse(AssistantAvatar::isValidFilename('dir/avatar.png'));
        $this->assertFalse(AssistantAvatar::isValidFilename('mon avatar.png')); // espace interdit
        $this->assertFalse(AssistantAvatar::isValidFilename('script.php'));
        $this->assertFalse(AssistantAvatar::isValidFilename('noext'));
        $this->assertFalse(AssistantAvatar::isValidFilename(''));
    }

    public function testIsDefaultMatchesSeededLibrary(): void
    {
        $this->assertTrue(AssistantAvatar::isDefault('alfred-robot.png'));
        $this->assertFalse(AssistantAvatar::isDefault('assistant-20240101-ab12cd34.png'));
    }

    public function testDeriveNameStripsKnownPrefixes(): void
    {
        $this->assertSame('Robot', AssistantAvatar::deriveName('alfred-robot.png'));
        // Un upload daté ne donne pas un nom commençant par un chiffre → fallback.
        $this->assertSame('Avatar', AssistantAvatar::deriveName('assistant-20240101-ab12cd34.png'));
    }
}
