<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Points à régler de l'administration (AdminAttention) : pastilles du menu,
 * bulle d'explication, mention « Administration » du menu du compte, lignes
 * « Config » du tableau de bord.
 *
 * Les formes sont pures (données en entrée) : chaque famille est vérifiée
 * sans base. Le câblage (menu, script, CSS, menu du compte, tableau de bord,
 * page Outils, chargement, documentation) est vérifié sur les fichiers.
 */
final class AdminAttentionTest extends TestCase
{
    private static function lire(string $relatif): string
    {
        $abs = ROOT . '/' . ltrim($relatif, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $relatif");
        return (string)file_get_contents($abs);
    }

    /** @return array<string, array<string,mixed>> constats indexés par id */
    private static function parId(array $signals): array
    {
        $out = [];
        foreach ($signals as $s) {
            $out[$s['id']] = $s;
        }
        return $out;
    }

    protected function tearDown(): void
    {
        ToolCatalog::setToday(null);
        AdminAttention::forget();
    }

    // ================================================================ Modèles

    public function testSansAucuneCleLesModelesSeTaisent(): void
    {
        // Le constat dédié « Aucune clé API » (pastille pulsante) dit tout.
        $this->assertSame([], AdminAttention::llmSignals([], [['provider' => 'anthropic', 'model' => 'x', 'display_name' => 'X']], [], 'openai', '', null));
    }

    public function testUneCleSansModeleActiveEstSignalee(): void
    {
        $s = self::parId(AdminAttention::llmSignals(['gemini'], [], [], '', '', null));
        $this->assertArrayHasKey('llm_fournisseur_sans_modele', $s);
        $this->assertSame('llms-models', $s['llm_fournisseur_sans_modele']['entry']);
        $this->assertSame(AdminAttention::WARN, $s['llm_fournisseur_sans_modele']['level']);
        $this->assertStringContainsString('Gemini', $s['llm_fournisseur_sans_modele']['text']);

        // Deux fournisseurs : un seul constat, qui les nomme tous les deux.
        $s = self::parId(AdminAttention::llmSignals(['deepseek', 'gemini'], [], [], '', '', null));
        $this->assertStringContainsString('Gemini', $s['llm_fournisseur_sans_modele']['text']);
        $this->assertStringContainsString('DeepSeek', $s['llm_fournisseur_sans_modele']['text']);
        $this->assertStringStartsWith('2 fournisseurs', $s['llm_fournisseur_sans_modele']['short']);
    }

    public function testLeLlmLocalNAPasDeModeleAActiver(): void
    {
        $this->assertSame([], AdminAttention::llmSignals(['local'], [], ['local' => 'LLM Local'], 'local', '', 'local'));
    }

    public function testUnModeleActiveSansCleEchoueEtEstSignaleEnRouge(): void
    {
        $modeles = [
            ['provider' => 'openai', 'model' => 'gpt-5.6', 'display_name' => 'GPT-5.6 Sol'],
            ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'display_name' => 'Claude Opus 5'],
        ];
        $dispo = ['openai::gpt-5.6' => 'GPT-5.6 Sol', 'anthropic::claude-opus-5' => 'Claude Opus 5'];
        $s = self::parId(AdminAttention::llmSignals(['openai'], $modeles, $dispo, 'openai::gpt-5.6', '', 'openai::gpt-5.6'));

        $this->assertArrayHasKey('llm_modeles_sans_cle', $s);
        $this->assertSame(AdminAttention::ALERT, $s['llm_modeles_sans_cle']['level']);
        $this->assertStringContainsString('Claude Opus 5', $s['llm_modeles_sans_cle']['text']);
        $this->assertStringNotContainsString('GPT-5.6', $s['llm_modeles_sans_cle']['text']);
        $this->assertStringContainsString('il échoue à chaque message : renseignez la clé (Clés API) ou retirez-le.', $s['llm_modeles_sans_cle']['text'], 'Accord au singulier');
        // Le défaut, lui, a sa clé : rien à dire de lui.
        $this->assertArrayNotHasKey('llm_defaut_sans_cle', $s);
        $this->assertArrayNotHasKey('llm_defaut_a_confirmer', $s);
    }

    public function testLeModeleParDefautSansCleFaitEchouerLesConversations(): void
    {
        $modeles = [
            ['provider' => 'openai', 'model' => 'gpt-5.6', 'display_name' => 'GPT-5.6 Sol'],
            ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'display_name' => 'Claude Opus 5'],
        ];
        $dispo = ['openai::gpt-5.6' => 'GPT-5.6 Sol', 'anthropic::claude-opus-5' => 'Claude Opus 5'];
        $s = self::parId(AdminAttention::llmSignals(['openai'], $modeles, $dispo, 'anthropic::claude-opus-5', '', 'anthropic::claude-opus-5'));

        $this->assertArrayHasKey('llm_defaut_sans_cle', $s);
        $this->assertSame(AdminAttention::ALERT, $s['llm_defaut_sans_cle']['level']);
        $this->assertStringContainsString('« Claude Opus 5 »', $s['llm_defaut_sans_cle']['text']);
    }

    public function testUnDefautNonEnregistreDemandeConfirmation(): void
    {
        // Même constat que l'encadré « Confirmer ce modèle » de la page Modèles :
        // le réglage ne désigne plus rien, MIA utilise le défaut effectif.
        $modeles = [['provider' => 'openai', 'model' => 'gpt-5.6', 'display_name' => 'GPT-5.6 Sol']];
        $dispo   = ['openai::gpt-5.6' => 'GPT-5.6 Sol'];
        $s = self::parId(AdminAttention::llmSignals(['openai'], $modeles, $dispo, 'openai::gpt-4o', '', 'openai::gpt-5.6'));
        $this->assertArrayHasKey('llm_defaut_a_confirmer', $s);
        $this->assertSame(AdminAttention::WARN, $s['llm_defaut_a_confirmer']['level']);
        $this->assertStringContainsString('« GPT-5.6 Sol »', $s['llm_defaut_a_confirmer']['text']);

        // Réglage valide : silence.
        $this->assertSame([], AdminAttention::llmSignals(['openai'], $modeles, $dispo, 'openai::gpt-5.6', '', 'openai::gpt-5.6'));
    }

    public function testLeModeleDeSecoursRetireOuSansCleEstSignale(): void
    {
        $modeles = [
            ['provider' => 'openai', 'model' => 'gpt-5.6', 'display_name' => 'GPT-5.6 Sol'],
            ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'display_name' => 'Claude Opus 5'],
        ];
        $dispo = ['openai::gpt-5.6' => 'GPT-5.6 Sol', 'anthropic::claude-opus-5' => 'Claude Opus 5'];

        // Retiré : le libellé vient du catalogue, pas du slug brut.
        $s = self::parId(AdminAttention::llmSignals(['openai', 'anthropic'], $modeles, $dispo, 'openai::gpt-5.6', 'anthropic::claude-sonnet-5', 'openai::gpt-5.6'));
        $this->assertArrayHasKey('llm_secours', $s);
        $this->assertStringContainsString('n\'est plus activé', $s['llm_secours']['text']);
        $this->assertStringNotContainsString('anthropic::', $s['llm_secours']['text']);

        // Activé mais sa clé a disparu.
        $s = self::parId(AdminAttention::llmSignals(['openai'], $modeles, $dispo, 'openai::gpt-5.6', 'anthropic::claude-opus-5', 'openai::gpt-5.6'));
        $this->assertStringContainsString('n\'a plus de clé API', $s['llm_secours']['text']);
    }

    public function testUneConfigurationCompleteNeDitRien(): void
    {
        $modeles = [
            ['provider' => 'openai', 'model' => 'gpt-5.6', 'display_name' => 'GPT-5.6 Sol'],
            ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'display_name' => 'Claude Opus 5'],
        ];
        $dispo = ['openai::gpt-5.6' => 'GPT-5.6 Sol', 'anthropic::claude-opus-5' => 'Claude Opus 5'];
        $this->assertSame([], AdminAttention::llmSignals(['anthropic', 'openai'], $modeles, $dispo, 'openai::gpt-5.6', 'anthropic::claude-opus-5', 'openai::gpt-5.6'));
    }

    // ================================================================ Groupes

    public function testUnGroupeDontAucunModeleNEstActiveLaisseSesMembresSansRien(): void
    {
        $dispo = ['openai::gpt-5.6' => 'GPT-5.6 Sol'];
        $s = self::parId(AdminAttention::groupSignals(
            ['Commerciaux' => ['anthropic::claude-opus-4-8'], 'Direction' => ['openai::gpt-4o', 'openai::gpt-5.6']],
            $dispo,
            ['openai']
        ));
        $this->assertSame(AdminAttention::ALERT, $s['groupe_sans_modele']['level']);
        $this->assertSame('user-groups', $s['groupe_sans_modele']['entry']);
        $this->assertStringContainsString('« Commerciaux »', $s['groupe_sans_modele']['text']);
        // Une liste dont une partie seulement est morte reste inoffensive.
        $this->assertStringNotContainsString('Direction', $s['groupe_sans_modele']['text']);

        // Rien de disponible, ou aucune clé : les constats de Modèles IA passent d'abord.
        $this->assertSame([], AdminAttention::groupSignals(['X' => ['a::b']], [], ['openai']));
        $this->assertSame([], AdminAttention::groupSignals(['X' => ['a::b']], $dispo, []));
    }

    // ================================================================ Assistants

    public function testUnAssistantPartageSansGroupeNestVuQueDesAdmins(): void
    {
        $profils = [
            ['name' => 'Juriste', 'is_personal' => 0, 'is_global' => 0, 'default_llm' => null, 'groups_n' => 0],
            ['name' => 'Perso', 'is_personal' => 1, 'is_global' => 0, 'default_llm' => null, 'groups_n' => 0],
            ['name' => 'Aide', 'is_personal' => 0, 'is_global' => 1, 'default_llm' => null, 'groups_n' => 0],
            ['name' => 'Commercial', 'is_personal' => 0, 'is_global' => 0, 'default_llm' => null, 'groups_n' => 2],
        ];
        $s = self::parId(AdminAttention::assistantSignals($profils, [], ['openai']));
        $this->assertSame(['assistant_sans_groupe'], array_keys($s));
        $this->assertSame('profiles', $s['assistant_sans_groupe']['entry']);
        $this->assertSame(AdminAttention::WARN, $s['assistant_sans_groupe']['level']);
        $this->assertStringContainsString('« Juriste »', $s['assistant_sans_groupe']['text']);
        foreach (['Perso', 'Aide', 'Commercial'] as $horsSujet) {
            $this->assertStringNotContainsString('« ' . $horsSujet . ' »', $s['assistant_sans_groupe']['text']);
        }
        $this->assertStringContainsString('cochez-le', $s['assistant_sans_groupe']['text'], 'Accord au singulier');
    }

    public function testLeModeleImposeParUnAssistantPeutEtreRetireOuSansCle(): void
    {
        $dispo = ['openai::gpt-5.6' => 'GPT-5.6 Sol', 'anthropic::claude-opus-5' => 'Claude Opus 5'];
        $profils = [
            ['name' => 'Ancien', 'is_personal' => 1, 'is_global' => 0, 'default_llm' => 'openai::gpt-4o', 'groups_n' => 0],
            ['name' => 'Claude', 'is_personal' => 1, 'is_global' => 0, 'default_llm' => 'anthropic::claude-opus-5', 'groups_n' => 0],
            ['name' => 'Suiveur', 'is_personal' => 1, 'is_global' => 0, 'default_llm' => '', 'groups_n' => 0],
        ];
        $s = self::parId(AdminAttention::assistantSignals($profils, $dispo, ['openai']));
        $this->assertSame(AdminAttention::WARN, $s['assistant_modele_retire']['level']);
        $this->assertStringContainsString('« Ancien »', $s['assistant_modele_retire']['text']);
        $this->assertStringContainsString('Il répond', $s['assistant_modele_retire']['text'], 'Accord au singulier');
        $this->assertSame(AdminAttention::ALERT, $s['assistant_modele_sans_cle']['level']);
        $this->assertStringContainsString('« Claude »', $s['assistant_modele_sans_cle']['text']);
        // « Défaut application » suit le défaut : le constat du défaut le couvre.
        foreach ($s as $signal) {
            $this->assertStringNotContainsString('Suiveur', $signal['text']);
        }

        // Aucune clé : le constat dédié passe d'abord.
        $this->assertSame([], AdminAttention::assistantSignals($profils, $dispo, []));
    }

    // ================================================================ Comptes

    public function testLesComptesEnAttenteSontNommes(): void
    {
        $this->assertSame([], AdminAttention::pendingAccountsSignals([]));

        $s = self::parId(AdminAttention::pendingAccountsSignals(['Jeanne Martin']));
        $this->assertSame('users', $s['comptes_en_attente']['entry']);
        $this->assertStringContainsString('Jeanne Martin', $s['comptes_en_attente']['text']);
        $this->assertStringContainsString('Activez-le', $s['comptes_en_attente']['text']);

        $s = self::parId(AdminAttention::pendingAccountsSignals(['A', 'B', 'C', 'D', 'E']));
        $this->assertStringStartsWith('5 comptes en attente', $s['comptes_en_attente']['text']);
        $this->assertStringContainsString('et 2 autres', $s['comptes_en_attente']['text'], 'Noms bornés : au-delà, un compte');
        $this->assertSame('5 comptes en attente d\'activation', $s['comptes_en_attente']['short']);
    }

    // ================================================================ Outils

    private static function outil(int $id, string $nom, array $champs = []): array
    {
        return $champs + [
            'id' => $id, 'name' => $nom, 'type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-2',
            'config_json' => '{}', 'api_key_enc' => null, 'is_active' => 1, 'last_test_ok' => null,
        ];
    }

    public function testChaqueOutilActifPorteSonConstatLePlusGrave(): void
    {
        ToolCatalog::setToday('2026-09-30');
        $outils = [
            self::outil(1, 'Sans clé', ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-image']),
            self::outil(2, 'Arrêté', ['model' => 'dall-e-3', 'last_test_ok' => 0]),
            self::outil(3, 'Test KO', ['last_test_ok' => 0]),
            self::outil(4, 'Seul', []),
            self::outil(5, 'Autorisé', []),
            self::outil(6, 'Éteint', ['is_active' => 0, 'provider' => 'gemini']),
            self::outil(7, 'Flux', ['type' => 'n8n', 'provider' => 'n8n', 'model' => 'webhook', 'api_key_enc' => 'x']),
        ];
        $s = self::parId(AdminAttention::toolSignals($outils, [5 => 1, 2 => 1, 3 => 1, 7 => 1], ['openai' => true], false, false, true));

        $this->assertSame(AdminAttention::ALERT, $s['outils_inutilisables']['level']);
        $this->assertStringContainsString('« Sans clé » (il manque la clé API Gemini)', $s['outils_inutilisables']['text']);
        $this->assertStringContainsString('« Flux » (il manque l\'URL de l\'instance n8n)', $s['outils_inutilisables']['text']);
        // Hors service AVANT test en échec : l'arrêt explique l'échec.
        $this->assertStringContainsString('« Arrêté »', $s['outils_hors_service']['text']);
        $this->assertStringNotContainsString('Arrêté', $s['outils_test_echec']['text']);
        $this->assertStringContainsString('« Test KO »', $s['outils_test_echec']['text']);
        $this->assertStringContainsString('« Seul »', $s['outils_sans_groupe']['text']);
        foreach ($s as $signal) {
            $this->assertSame('tools', $signal['entry']);
            $this->assertStringNotContainsString('Éteint', $signal['text'], 'Un outil inactif n\'est proposé à personne');
            $this->assertStringNotContainsString('Autorisé', $signal['text']);
        }
    }

    public function testLaVoixSansCleOpenAiNApparaitPas(): void
    {
        $s = self::parId(AdminAttention::toolSignals([], [], [], true, true, false));
        $this->assertStringContainsString('La dictée vocale et la conversation vocale sont activées', $s['voix_sans_openai']['text']);
        $this->assertStringContainsString('désactivez-les', $s['voix_sans_openai']['text']);

        $s = self::parId(AdminAttention::toolSignals([], [], [], true, false, false));
        $this->assertStringContainsString('La dictée vocale est activée', $s['voix_sans_openai']['text']);
        $this->assertStringContainsString('désactivez-la', $s['voix_sans_openai']['text']);

        $this->assertSame([], AdminAttention::toolSignals([], [], [], true, true, true), 'Avec une clé OpenAI, rien à dire');
        $this->assertSame([], AdminAttention::toolSignals([], [], [], false, false, false), 'Désactivées : rien à dire');
    }

    public function testLaReglePrerequisDUnOutilEstUniqueEtPure(): void
    {
        $image = self::outil(1, 'Image', []);
        $this->assertSame([], ChatTool::missingPrerequisites($image, ['openai' => true], [1 => $image]));
        $this->assertSame(['la clé API OpenAI'], ChatTool::missingPrerequisites($image, [], [1 => $image], ['openai' => 'OpenAI']));
        $image['api_key_enc'] = 'dediee';
        $this->assertSame([], ChatTool::missingPrerequisites($image, [], []), 'Une clé dédiée suffit');

        $site = self::outil(2, 'Site', ['type' => 'html_illustrated', 'provider' => 'anthropic', 'config_json' => '{"image_tool_id":9}']);
        $this->assertSame(['un outil image source'], ChatTool::missingPrerequisites($site, ['anthropic' => true], []));
        $source = self::outil(9, 'Source', ['provider' => 'gemini']);
        $this->assertSame(
            ['la clé API Gemini (outil image source)'],
            ChatTool::missingPrerequisites($site, ['anthropic' => true], [9 => $source], ['gemini' => 'Gemini'])
        );

        // La page Outils n'a plus sa propre copie de la règle.
        $page = self::lire('templates/admin/tools.php');
        $this->assertStringContainsString('ChatTool::missingPrerequisites($tool, $providersWithKey, $toolById, $providerLabels)', $page);
        $this->assertStringNotContainsString('$baseUsable', $page);
    }

    // ================================================================ Connecteurs, bases, connexion, worker

    public function testUnConnecteurActiveSansIdentifiantsEstInvisibleEtSignale(): void
    {
        $rows = [
            ['key' => 'web', 'label' => 'Recherche Internet', 'family' => null, 'enabled' => true, 'configured' => true],
            ['key' => 'drive', 'label' => 'Google Drive', 'family' => 'google', 'enabled' => true, 'configured' => false],
            ['key' => 'gmail', 'label' => 'Gmail', 'family' => 'google', 'enabled' => true, 'configured' => false],
            ['key' => 'tasks', 'label' => 'Google Tasks', 'family' => 'google', 'enabled' => false, 'configured' => false],
            ['key' => 'slack', 'label' => 'Slack', 'family' => 'slack', 'enabled' => true, 'configured' => false],
            ['key' => 'onedrive', 'label' => 'OneDrive', 'family' => 'microsoft', 'enabled' => true, 'configured' => false],
        ];
        $s = self::parId(AdminAttention::connectorSignals($rows));
        $texte = $s['connecteurs_indisponibles']['text'];
        $this->assertSame('connectors', $s['connecteurs_indisponibles']['entry']);
        $this->assertStringContainsString('Google Drive, Gmail : identifiants Google absents', $texte, 'Regroupés par remède');
        $this->assertStringContainsString('Slack : application Slack absente', $texte);
        $this->assertStringContainsString('OneDrive : application Azure absente (Capacités › Sources synchronisées)', $texte);
        $this->assertStringNotContainsString('Google Tasks', $texte, 'Masqué par l\'administrateur : rien à signaler');
        $this->assertStringNotContainsString('Recherche Internet', $texte);
        $this->assertStringContainsString('masquez ces connecteurs', $texte);
        $this->assertSame('4 connecteurs activés sans identifiants', $s['connecteurs_indisponibles']['short']);

        $this->assertSame([], AdminAttention::connectorSignals([$rows[0], $rows[3]]));
    }

    public function testLesBasesEnPanneSontSignaleesLaOuElleSeCorrigent(): void
    {
        $bases = [
            ['name' => 'Cerveau', 'backend' => 'mybrain', 'remote_status' => 'refused'],
            ['name' => 'Cerveau joignable', 'backend' => 'mybrain', 'remote_status' => 'unreachable'],
            ['name' => 'Contrats', 'backend' => 'local', 'sync_provider' => 'sharepoint', 'sync_folder_ref' => 'https://x', 'sync_enabled' => 1, 'sync_status' => 'ok'],
            ['name' => 'Procédures', 'backend' => 'local', 'sync_provider' => 'drive', 'sync_folder_ref' => 'abc', 'sync_enabled' => 1, 'sync_status' => 'error'],
            ['name' => 'Perso Drive', 'backend' => 'local', 'sync_provider' => 'drive', 'sync_folder_ref' => 'def', 'sync_enabled' => 1, 'sync_status' => 'ok', 'sync_owner_user_id' => 3],
            ['name' => 'Pause', 'backend' => 'local', 'sync_provider' => 'sharepoint', 'sync_folder_ref' => 'https://y', 'sync_enabled' => 0, 'sync_status' => 'error'],
        ];
        $configures = ['drive' => true, 'sharepoint' => false, 'gcalendar' => false, 'onedrive' => false];
        $s = self::parId(AdminAttention::kbSignals($bases, $configures, false, true));

        $this->assertSame(AdminAttention::ALERT, $s['mybrain_refuse']['level']);
        $this->assertStringContainsString('« Cerveau »', $s['mybrain_refuse']['text']);
        $this->assertStringNotContainsString('joignable', $s['mybrain_refuse']['text'], 'Une panne passagère ne grise rien');

        $this->assertSame('knowledge-bases-sync', $s['bases_source_sans_identifiants']['entry']);
        $this->assertStringContainsString('« Contrats » (SharePoint)', $s['bases_source_sans_identifiants']['text']);
        // Drive lu par son propriétaire : c'est l'OAuth Google qui compte (absent ici).
        $this->assertStringContainsString('« Perso Drive » (Google Drive)', $s['bases_source_sans_identifiants']['text']);
        $this->assertStringNotContainsString('Pause', $s['bases_source_sans_identifiants']['text'], 'Synchronisation coupée : rien à attendre');

        $this->assertStringContainsString('« Procédures »', $s['bases_sync_echec']['text']);
        $this->assertArrayNotHasKey('bases_sans_vectorisation', $s);

        // Même bases, OAuth Google configuré : le Drive du propriétaire se synchronise.
        $s = self::parId(AdminAttention::kbSignals($bases, $configures, true, true));
        $this->assertStringNotContainsString('Perso Drive', $s['bases_source_sans_identifiants']['text']);
    }

    public function testSansCleDeVectorisationLesBasesLocalesCherchentParMots(): void
    {
        $locale = [['name' => 'Docs', 'backend' => 'local']];
        $s = self::parId(AdminAttention::kbSignals($locale, [], false, false));
        $this->assertSame('knowledge-bases', $s['bases_sans_vectorisation']['entry']);

        // Un cerveau MyBrain n'a rien à vectoriser ; sans base, rien à dire.
        $this->assertSame([], AdminAttention::kbSignals([['name' => 'C', 'backend' => 'mybrain', 'remote_status' => 'ok']], [], false, false));
        $this->assertSame([], AdminAttention::kbSignals([], [], false, false));
    }

    public function testUneConnexionActiveeSansIdentifiantsNAffichePasSonBouton(): void
    {
        $s = self::parId(AdminAttention::ssoSignals(['Google' => [true, true], 'Microsoft' => [true, false]]));
        $this->assertSame('sso', $s['sso_sans_identifiants']['entry']);
        $this->assertStringContainsString('Connexion Microsoft activée', $s['sso_sans_identifiants']['text']);
        $this->assertStringNotContainsString('Google', $s['sso_sans_identifiants']['text']);
        $this->assertSame([], AdminAttention::ssoSignals(['Google' => [false, false], 'Microsoft' => [false, true]]));
    }

    public function testLeWorkerArreteOuPerimeEstSignaleMaisPasUneInstallationSansWorker(): void
    {
        $now = 1_800_000_000;
        $this->assertNull(AdminAttention::workerSignal(null, '1.3.0.5', $now), 'Jamais vu : installation sans worker');
        $this->assertNull(AdminAttention::workerSignal(['at' => $now - 20, 'version' => '1.3.0.5'], '1.3.0.5', $now));
        // Un redémarrage (quelques dizaines de secondes) ne suffit pas.
        $this->assertNull(AdminAttention::workerSignal(['at' => $now - AdminAttention::WORKER_SILENCE_SEC + 1, 'version' => '1.3.0.5'], '1.3.0.5', $now));

        $arret = AdminAttention::workerSignal(['at' => $now - 900, 'version' => '1.3.0.5'], '1.3.0.5', $now);
        $this->assertSame('diagnostics', $arret['entry']);
        $this->assertStringContainsString('depuis 15 minutes', $arret['text']);

        $perime = AdminAttention::workerSignal(['at' => $now - 5, 'version' => '1.3.0.2'], '1.3.0.5', $now);
        $this->assertStringContainsString('1.3.0.2 au lieu de 1.3.0.5', $perime['text']);
    }

    // ================================================================ Menu : entrée, groupe, niveau

    public function testLaBulleDUneEntreeDitToutEtCelleDuGroupeResume(): void
    {
        $signals = [
            ['id' => 'a', 'entry' => 'llms-models', 'level' => AdminAttention::WARN, 'short' => 'court A', 'text' => 'Texte A.', 'dedicated' => false],
            ['id' => 'b', 'entry' => 'llms-models', 'level' => AdminAttention::ALERT, 'short' => 'court B', 'text' => 'Texte B.', 'dedicated' => false],
            ['id' => 'c', 'entry' => 'llms-keys', 'level' => AdminAttention::ALERT, 'short' => 'aucune clé API', 'text' => AdminAttention::TEXTE_AUCUNE_CLE, 'dedicated' => true],
            ['id' => 'd', 'entry' => 'profiles', 'level' => AdminAttention::WARN, 'short' => 'court D', 'text' => 'Texte D.', 'dedicated' => false],
        ];

        $entree = AdminAttention::forEntry($signals, 'llms-models');
        $this->assertSame(AdminAttention::ALERT, $entree['level'], 'Le pire niveau l\'emporte');
        $this->assertSame("• Texte A.\n• Texte B.", $entree['text']);
        $this->assertSame(['level' => AdminAttention::WARN, 'text' => 'Texte D.'], AdminAttention::forEntry($signals, 'profiles'));
        $this->assertNull(AdminAttention::forEntry($signals, 'tools'));
        // Le constat dédié a sa propre pastille : la mécanique commune le laisse.
        $this->assertNull(AdminAttention::forEntry($signals, 'llms-keys'));

        $groupe = AdminAttention::forGroup($signals, 'models');
        $this->assertSame(AdminAttention::ALERT, $groupe['level']);
        $this->assertSame("À régler dans ce menu :\n• Modèles : court A\n• Modèles : court B", $groupe['text']);
        $this->assertNull(AdminAttention::forGroup($signals, 'capabilities'));
        // Assistants et bases : pages de l'espace de travail, sans groupe du menu.
        foreach (AdminAttention::GROUPS as $entrees) {
            $this->assertNotContains('profiles', $entrees);
            $this->assertNotContains('knowledge-bases', $entrees);
        }

        $this->assertSame(AdminAttention::ALERT, AdminAttention::worstLevel($signals));
        $this->assertNull(AdminAttention::worstLevel([]));
        $this->assertCount(3, AdminAttention::shown($signals));
    }

    public function testChaqueEntreeEtChaqueGroupeExistentDansLeMenu(): void
    {
        $nav    = self::lire('templates/partials/admin-nav.php');
        $tuiles = self::lire('templates/partials/sidebar-shortcuts.php');
        $routes = self::lire('public/index.php');
        foreach (AdminAttention::ENTRIES as $entry => [$libelle, $chemin, $href]) {
            $this->assertStringContainsString("get('$href',", $routes, "Route absente : $href");
            if (in_array($entry, ['profiles', 'knowledge-bases'], true)) {
                // Pages de l'espace de travail : la pastille va sur leur tuile.
                $this->assertStringContainsString("'$entry'", $tuiles, "Tuile non câblée : $entry");
                continue;
            }
            $this->assertSame($href, AdminAccess::PAGE_HREFS[$entry], "Adresse du constat ≠ adresse du menu : $entry");
            $this->assertSame($libelle, AdminMenu::pageLabel($entry), "Libellé du constat ≠ libellé du menu : $entry");
            // « Clés API » ne porte que le constat dédié (pastille pulsante).
            if ($entry !== 'llms-keys') {
                $this->assertStringContainsString("AdminAttention::forEntry(\$navAttention, '$entry')", $nav, "Entrée non câblée : $entry");
            }
        }
        foreach (array_keys(AdminAttention::GROUPS) as $groupe) {
            $this->assertArrayHasKey($groupe, AdminMenu::ENTRIES, "Groupe sans rubrique : $groupe");
            $this->assertSame($groupe, AdminMenu::ENTRIES[$groupe]['group']);
            $this->assertStringContainsString("\$navGroup('$groupe',", $nav);
            $this->assertStringContainsString("AdminAttention::forGroup(\$navAttention, '$groupe')", $nav, "Groupe non câblé : $groupe");
            foreach (AdminAttention::GROUPS[$groupe] as $entry) {
                $this->assertArrayHasKey($entry, AdminAttention::ENTRIES);
                $this->assertContains($entry, AdminAccess::ENTRY_PAGES[$groupe], "$entry n'est pas une page de la rubrique $groupe");
            }
        }
    }

    // ================================================================ Câblage

    public function testLeMenuCalculeUneFoisEtPoseAttributsPastilleEtBulle(): void
    {
        $nav = self::lire('templates/partials/admin-nav.php');
        // Comptes qui administrent (bouclier), et seulement les constats des
        // pages que leur rôle ouvre.
        $this->assertStringContainsString("\$navAttention = (\$navVariant === 'admin' && AdminAccess::hasAdministration(\$navUser))\n    ? AdminAccess::filterAttention(AdminAttention::current(), \$navUser)\n    : [];", $nav);
        $this->assertLessThan(
            (int)strpos($nav, "partials/header-avatar-btn.php'"),
            (int)strpos($nav, 'AdminAttention::current()'),
            'Calculé avant le header mobile, dont le bouton du tiroir porte une pastille.'
        );
        $this->assertStringContainsString("' data-nav-signal aria-describedby=\"' . \$id . '\"'", $nav);
        $this->assertStringContainsString('<span class="nav-signal-dot', $nav);
        $this->assertStringContainsString('<div class="nav-signal-hint', $nav);
        $this->assertStringContainsString('nav-signals.js', $nav);
        // Les pastilles historiques ont désormais leur bulle, plus de `title`.
        $this->assertStringContainsString('$llmSetupTitle  = AdminAttention::texteAucuneCle();', $nav);
        $this->assertStringNotContainsString("' title=\"' . htmlspecialchars(\$llmSetupTitle)", $nav);
        $this->assertStringNotContainsString("' title=\"' . htmlspecialchars(\$budgetNavTitle)", $nav);
    }

    public function testLaBulleEstFixeAuDessusDeLaPastilleEtTexteAuDoigt(): void
    {
        $js = self::lire('public/assets/js/nav-signals.js');
        $this->assertStringContainsString("getAttribute('aria-describedby')", $js);
        $this->assertStringContainsString("'.nav-signal-dot, .nav-attention-dot, .nav-budget-dot'", $js);
        $this->assertStringContainsString('r.top - hauteur - ECART', $js, 'Au-dessus de la pastille');
        $this->assertStringContainsString("'(hover: hover)'", $js);
        $this->assertStringContainsString("document.body.appendChild(bulle)", $js, 'Une seule bulle, hors de la barre qui défile');

        $css = self::lire('public/assets/css/main.css');
        foreach (['.nav-signal-dot.is-alert', '.nav-hint-bubble {', '.nav-hint-bubble[hidden] { display: none !important; }', '.admin-burger .nav-signal-dot'] as $regle) {
            $this->assertStringContainsString($regle, $css);
        }
        $this->assertMatchesRegularExpression('/@media \(hover: none\) \{\s*\.nav-signal-hint \{\s*display: block;/', $css, 'Au doigt, le texte s\'affiche sous l\'entrée');
        $pos = strpos($css, '.nav-hint-bubble {');
        $this->assertStringContainsString('position: fixed;', substr($css, (int)$pos, 200));
    }

    public function testLeBoutonAdminAnnonceLesPointsARegler(): void
    {
        $actions = self::lire('templates/partials/user-account-actions.php');
        $this->assertStringContainsString('$uaHasAdmin ? AdminAccess::filterAttention(AdminAttention::current(), $uaUser) : []', $actions, 'Filtré par le rôle, seulement pour qui administre : le bouton « Admin » porte le décompte.');
        $this->assertStringContainsString("I18n::plural(\$uaBadge['count'], '{n} point à régler', '{n} points à régler')", $actions);
        $this->assertStringContainsString('class="user-action-count', $actions);

        $en = (array)(require ROOT . '/lang/en.php');
        foreach (['{n} point à régler', '{n} points à régler'] as $cle) {
            $this->assertArrayHasKey($cle, $en, "Traduction absente : $cle");
        }
        // L'avatar reste réservé aux connexions à reconnecter.
        foreach (['templates/partials/user-account-trigger.php', 'templates/partials/header-avatar-btn.php'] as $partial) {
            $this->assertStringNotContainsString('AdminAttention', self::lire($partial));
        }
    }

    public function testLeTableauDeBordListeLesMemesConstats(): void
    {
        $tpl = self::lire('templates/admin/index.php');
        $this->assertStringContainsString('$configSignals = AdminAttention::shown(AdminAttention::current());', $tpl);
        $this->assertStringContainsString('AdminAttention::ENTRIES[$sig[\'entry\']]', $tpl);
        $this->assertStringNotContainsString('$inactiveUsers', $tpl, 'Les comptes en attente sont un point à régler, sans doublon');
    }

    public function testLaClasseEstChargeeEtLesLecturesRestentLocales(): void
    {
        $this->assertStringContainsString("require ROOT . '/src/Helpers/AdminAttention.php';", self::lire('config/classes.php'));
        $this->assertStringContainsString("'/src/Helpers/AdminAttention.php',", self::lire('tests/bootstrap.php'));

        $code = self::lire('src/Helpers/AdminAttention.php');
        // Calculée à chaque page d'administration : aucun appel réseau.
        foreach (['whoami(', 'healthCheck(', 'gethostbyname(', 'curl_', 'file_get_contents('] as $interdit) {
            $this->assertStringNotContainsString($interdit, $code, "Appel réseau interdit : $interdit");
        }
        $this->assertStringContainsString('EmbeddingService::PROVIDER_ORDER', $code);
        $this->assertStringContainsString('JobQueue::workerHeartbeat()', $code);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationEnParle(): void
    {
        $this->assertStringContainsString('Points à régler', self::lire('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('points à régler', mb_strtolower(self::lire('docs/NOUVEAUTES.md')));
        $this->assertStringContainsString('AdminAttention', self::lire('CLAUDE.md'));
    }
}
