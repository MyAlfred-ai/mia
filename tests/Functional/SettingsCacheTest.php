<?php

declare(strict_types=1);

/**
 * Cache mémoire des Settings + garde de version du worker (JobQueue).
 *
 * Régression vécue : dans le worker de jobs, `markWorkerAlive()` (un set())
 * précédait le premier get(). Le cache, devenu « non vide », passait pour
 * chargé et la table n'était JAMAIS lue : tous les réglages valaient leur
 * défaut pour toute la vie du process — la sync des bases de connaissances
 * échouait en « Clé API Google non configurée » alors que la clé était en
 * base, et les jobs de chat ignoraient LLM par défaut et vocabulaire commun.
 */
final class SettingsCacheTest extends DatabaseTestCase
{
    private const KEYS = ['test_cache_a', 'test_cache_b', 'app_code_version'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeKeys();
        Settings::refresh();
    }

    protected function tearDown(): void
    {
        // La table settings n'est pas vidée entre les tests : on nettoie nos
        // clés et on invalide le cache pour ne rien laisser aux tests suivants.
        // tearDown() tourne AUSSI quand setUp() a skippé (base indisponible) :
        // ne rien tenter sans connexion.
        if (self::$pdo !== null) {
            $this->purgeKeys();
            Settings::refresh();
        }
        parent::tearDown();
    }

    private function purgeKeys(): void
    {
        $in = implode(',', array_fill(0, count(self::KEYS), '?'));
        $this->db()->prepare("DELETE FROM settings WHERE `key` IN ($in)")->execute(self::KEYS);
    }

    public function testUnSetAvantLePremierGetNeMasquePasLaTable(): void
    {
        // La ligne existe en base…
        $this->db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)')
            ->execute(['test_cache_a', 'valeur-en-base']);

        // …et le process démarre par un set() (ordre réel du worker :
        // heartbeat d'abord). Le get() suivant doit quand même lire la table.
        Settings::set('test_cache_b', 'heartbeat');

        $this->assertSame('valeur-en-base', Settings::get('test_cache_a', ''));
    }

    public function testRefreshRelitLaTable(): void
    {
        Settings::set('test_cache_a', 'v1');
        $this->assertSame('v1', Settings::get('test_cache_a'));

        // Modification par un AUTRE process (écriture directe, hors cache).
        $this->db()->prepare('UPDATE settings SET `value` = ? WHERE `key` = ?')
            ->execute(['v2', 'test_cache_a']);
        $this->assertSame('v1', Settings::get('test_cache_a'), 'le cache sert la valeur connue');

        Settings::refresh();
        $this->assertSame('v2', Settings::get('test_cache_a'), 'après refresh, la table fait foi');
    }

    public function testPublishCodeVersionEtWorkerOutdated(): void
    {
        JobQueue::publishCodeVersion();
        $this->assertFalse(JobQueue::workerOutdated(), 'même version publiée = worker à jour');

        // Une app plus récente publie une autre version.
        Settings::set('app_code_version', 'autre-version');
        $this->assertTrue(JobQueue::workerOutdated());

        // L'app de CE code re-publie : réalignement.
        JobQueue::publishCodeVersion();
        $this->assertFalse(JobQueue::workerOutdated());
    }

    public function testVersionJamaisPublieeNeBloquePas(): void
    {
        $this->assertFalse(JobQueue::workerOutdated(), 'pas de version publiée = pas d\'avis');
    }
}
