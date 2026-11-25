<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Sections repliées du volet sur base réelle (migration 109).
 *
 * Ce que ces tests protègent : un compte neuf n'a rien de choisi (les défauts
 * s'appliquent : tout déplié), un état enregistré est relu
 * par User::findById — la ligne de la session, celle que lit head-meta.php —
 * sur n'importe quel appareil, une forme forgée est nettoyée AVANT d'atteindre
 * la base, et l'état d'un compte ne touche jamais celui d'un autre.
 */
final class SidebarPreferencesPipelineTest extends DatabaseTestCase
{
    public function testUnCompteNeufSuitLesDefauts(): void
    {
        $this->insertUser(1);
        $user = User::findById(1);
        $this->assertArrayHasKey('ui_sidebar', $user, 'La colonne remonte dans la session (SELECT *).');
        $this->assertNull($user['ui_sidebar']);
        $config = SidebarPreferences::clientConfig($user);
        $this->assertFalse($config['saved']);
        $this->assertTrue(SidebarPreferences::isOpen(SidebarPreferences::stateOf($user), 'spaces'));
        $this->assertTrue(SidebarPreferences::isOpen(SidebarPreferences::stateOf($user), 'pinned'));
    }

    public function testLEtatEnregistreEstReluParLeCompte(): void
    {
        $this->insertUser(1);
        $this->insertUser(2);
        SidebarPreferences::save(1, '{"spaces":false,"history":false,"groups":{"today":false,"m2026-3":true}}');

        $user = User::findById(1);
        $etat = SidebarPreferences::stateOf($user);
        $this->assertTrue(SidebarPreferences::clientConfig($user)['saved']);
        $this->assertFalse(SidebarPreferences::isOpen($etat, 'spaces'));
        $this->assertFalse(SidebarPreferences::isOpen($etat, 'history'));
        $this->assertTrue(SidebarPreferences::isOpen($etat, 'pinned'), 'Non touchée : le défaut.');
        $this->assertSame(['today' => false, 'm2026-3' => true], $etat['groups']);

        $this->assertNull(User::findById(2)['ui_sidebar'], 'L’état d’un compte ne touche pas les autres.');
    }

    public function testUneFormeForgeeEstNettoyeeAvantLaBase(): void
    {
        $this->insertUser(1);
        $garde = SidebarPreferences::save(1, '{"spaces":"<script>","pinned":true,"x":1,"groups":{"today":true,"<b>":false}}');
        $this->assertSame(['pinned' => true, 'groups' => ['today' => true]], $garde);
        $brut = (string)$this->db()->query('SELECT ui_sidebar FROM users WHERE id = 1')->fetchColumn();
        $this->assertSame('{"pinned":true,"groups":{"today":true}}', $brut);

        // Un état vidé reste un état ENREGISTRÉ : l'ancien navigateur n'est
        // plus repris, les défauts s'appliquent.
        SidebarPreferences::save(1, '');
        $user = User::findById(1);
        $this->assertSame('{"groups":{}}', $user['ui_sidebar']);
        $this->assertTrue(SidebarPreferences::clientConfig($user)['saved']);
    }
}
