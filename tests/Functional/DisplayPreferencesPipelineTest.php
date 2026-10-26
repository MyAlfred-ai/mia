<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Réglages d'affichage sur base réelle (migration 086).
 *
 * Ce que ces tests protègent : les colonnes existent avec leurs défauts (un
 * compte qui n'a rien choisi garde le rendu d'avant), un enregistrement
 * partiel ne remet jamais l'autre réglage à zéro, une valeur forgée est
 * ramenée au défaut AVANT d'atteindre la base, et la ligne relue par
 * User::findById (celle de la session) produit les attributs de <html>.
 */
final class DisplayPreferencesPipelineTest extends DatabaseTestCase
{
    private function prefs(int $id): array
    {
        return (array)$this->db()->query("SELECT ui_theme, ui_scale FROM users WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    }

    public function testUnCompteNeufNAChoisiNiThemeNiTaille(): void
    {
        $this->insertUser(1);
        $this->assertSame(['ui_theme' => '', 'ui_scale' => 100], array_map(
            static fn($v) => is_numeric($v) ? (int)$v : $v,
            $this->prefs(1)
        ));
        $this->assertSame('data-theme="dark"', DisplayPreferences::htmlAttributesFor(User::findById(1), 'dark'));
    }

    public function testUnEnregistrementPartielNEffaceRienDAutre(): void
    {
        $this->insertUser(1);
        DisplayPreferences::save(1, 'system', 110);
        DisplayPreferences::save(1, null, 125);
        $this->assertSame('system', $this->prefs(1)['ui_theme'], 'La taille seule ne touche pas au thème.');
        DisplayPreferences::save(1, 'light', null);
        $this->assertSame(125, (int)$this->prefs(1)['ui_scale'], 'Le thème seul ne touche pas à la taille.');
        DisplayPreferences::save(1);
        $this->assertSame(['ui_theme' => 'light', 'ui_scale' => 125], ['ui_theme' => $this->prefs(1)['ui_theme'], 'ui_scale' => (int)$this->prefs(1)['ui_scale']]);

        $this->assertSame(
            'data-theme="light" data-theme-pref="light" data-ui-scale="125" style="--ui-scale: 1.25"',
            DisplayPreferences::htmlAttributesFor(User::findById(1), 'dark')
        );
    }

    public function testContrasteEtToucheDEnvoiSEnregistrentSeuls(): void
    {
        $this->insertUser(1);
        DisplayPreferences::save(1, 'dark', 110);
        DisplayPreferences::save(1, null, null, true, 'mod_enter');
        $row = User::findById(1);
        $this->assertTrue(DisplayPreferences::contrastOf($row));
        $this->assertSame('mod_enter', DisplayPreferences::sendKeyOf($row));
        $this->assertSame('dark', DisplayPreferences::themeOf($row), 'Contraste et touche d\'envoi ne touchent pas au thème.');
        $this->assertSame(110, DisplayPreferences::scaleOf($row));

        DisplayPreferences::save(1, null, null, false, null);
        $row = User::findById(1);
        $this->assertFalse(DisplayPreferences::contrastOf($row));
        $this->assertSame('mod_enter', DisplayPreferences::sendKeyOf($row), 'Le contraste seul ne touche pas à la touche d\'envoi.');

        DisplayPreferences::save(1, null, null, null, 'n_importe');
        $this->assertSame('enter', (string)$this->db()->query('SELECT ui_send_key FROM users WHERE id = 1')->fetchColumn());
    }

    public function testUneValeurForgeeNAtteintPasLaBase(): void
    {
        $this->insertUser(1);
        DisplayPreferences::save(1, '<script>', 999);
        $this->assertSame('', $this->prefs(1)['ui_theme']);
        $this->assertSame(DisplayPreferences::MAX_SCALE, (int)$this->prefs(1)['ui_scale'], 'Une taille hors course est bornée, jamais écrite telle quelle.');
    }

    public function testLeChoixDUnCompteNeTouchePasLesAutres(): void
    {
        $this->insertUser(1);
        $this->insertUser(2);
        DisplayPreferences::save(1, 'dark', 90);
        $this->assertSame('', $this->prefs(2)['ui_theme']);
        $this->assertSame(100, (int)$this->prefs(2)['ui_scale']);
    }
}
