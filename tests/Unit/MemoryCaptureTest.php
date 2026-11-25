<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Demandes explicites à la mémoire depuis le chat (« souviens-toi que… »,
 * « corrige ta mémoire : … », « oublie que… ») — formes pures :
 *   - looksLikeRequest : porte lexicale FR/EN (évite un appel au juge sur
 *     chaque message, et surtout les faux positifs : « rappelle-moi demain »,
 *     « oublie la version précédente », « remember to call him ») ;
 *   - userPrompt / systemPrompt : ce que lit le juge (numéros locaux, jamais
 *     un identifiant SQL) ;
 *   - parseOps : lecture tolérante ET bornée de sa réponse ;
 *   - la note injectée dans le prompt de la réponse (ChatController).
 */
final class MemoryCaptureTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------------------------------------------------------------------
    // Porte lexicale
    // ---------------------------------------------------------------------

    public static function demandes(): array
    {
        return [
            ["S'il te plaît, souviens-toi que je préfère les réunions du matin"],
            ['Rappelle-toi que je travaille dans le département marketing'],
            ["J'aimerai que tu te souviennes que j'aime les réponses courtes concernant les formations"],
            ['Retiens que mon fuseau horaire est Paris'],
            ['Mémorise mon poste : responsable qualité'],
            ['Garde en mémoire que je code en PHP'],
            ["N'oublie pas que je suis daltonien"],
            // Oublier, corriger : un objet mémoire.
            ['Oublie que je travaille chez Durand'],
            ["Oubliez qu'on avait parlé du projet Atlas"],
            ['Ne retiens plus que je suis à Lyon'],
            ['Corrige ta mémoire : je suis passé chez Lambert'],
            ['Que contient votre mémoire sur moi ?'],
            // Anglais.
            ['Please remember that I prefer short answers'],
            ['Remember my role: head of sales'],
            ['Keep in mind that I work in finance'],
            ["Don't forget that I'm colour-blind"],
            ['Memorize this: my team is five people'],
            ['Forget that I work at Acme'],
            ['Please update your memory: I moved to Berlin'],
        ];
    }

    #[DataProvider('demandes')]
    public function testUneDemandeALaMemoireEstReconnue(string $message): void
    {
        $this->assertTrue(MemoryCapture::looksLikeRequest($message), $message);
    }

    public static function messagesOrdinaires(): array
    {
        return [
            ['Rappelle-moi demain de relancer le fournisseur'],   // rappel d'agenda
            ['Quelle est la capitale du Portugal ?'],
            ['Fais-moi un résumé de ce document'],
            ['Merci beaucoup pour ton aide'],
            ['Oublie la version précédente, repars de zéro'],     // vise le texte en cours
            ['Corrige ce paragraphe, il est trop long'],
            ['Remember to call him tomorrow'],                     // rappel, pas mémorisation
            ['Forget the previous draft'],
            ['Can you summarise this email?'],
            [''],
        ];
    }

    #[DataProvider('messagesOrdinaires')]
    public function testUnMessageOrdinaireNeCouteAucunAppel(string $message): void
    {
        $this->assertFalse(MemoryCapture::looksLikeRequest($message), $message);
    }

    public function testUnTexteDemesureNEstPasExamine(): void
    {
        $this->assertFalse(MemoryCapture::looksLikeRequest('Souviens-toi que ' . str_repeat('a', 2100)));
    }

    // ---------------------------------------------------------------------
    // Ce que lit le juge
    // ---------------------------------------------------------------------

    public function testLeJugeVoitDesNumerosLocauxJamaisDesIdentifiants(): void
    {
        $prompt = MemoryCapture::userPrompt('Utilisateur : bonjour', 'Oublie que je suis à Lyon', [
            ['id' => 9041, 'content' => 'Habite à Lyon', 'theme' => 'profil'],
            ['id' => 77, 'content' => str_repeat('long ', 80), 'theme' => 'sujets'],
            ['id' => 3, 'content' => 'Sans thème'],
        ]);

        $this->assertStringContainsString("1. [profil] Habite à Lyon", $prompt);
        $this->assertStringContainsString("3. [autre] Sans thème", $prompt);
        $this->assertStringNotContainsString('9041', $prompt);
        $this->assertStringContainsString('Conversation en cours', $prompt);
        $this->assertStringContainsString('Oublie que je suis à Lyon', $prompt);
        // Un souvenir est présenté borné.
        preg_match('/^2\. \[sujets\] (.+)$/m', $prompt, $m);
        $this->assertLessThanOrEqual(MemoryCapture::ITEM_CHARS, mb_strlen($m[1] ?? ''));

        $this->assertStringContainsString('(aucun)', MemoryCapture::userPrompt('', 'Retiens que…', []));
    }

    public function testLaConsigneDuJugeConnaitChaqueThemeEtLesTroisActions(): void
    {
        $prompt = MemoryCapture::systemPrompt();
        foreach (array_keys(UserMemory::THEMES) as $theme) {
            $this->assertStringContainsString($theme, $prompt);
        }
        foreach (['"ajouter"', '"modifier"', '"oublier"', '"operations"', '"tout_effacer"'] as $needle) {
            $this->assertStringContainsString($needle, $prompt);
        }
        // Les faux positifs de la porte sont nommés au juge.
        $this->assertStringContainsString('rappelle-moi', $prompt);
        $this->assertStringContainsString('oublie la version précédente', $prompt);
    }

    // ---------------------------------------------------------------------
    // Lecture de la réponse du juge
    // ---------------------------------------------------------------------

    public function testLaReponseEnveloppeeEstLue(): void
    {
        $parsed = MemoryCapture::parseOps(
            "Voici :\n```json\n{\"operations\": [{\"action\": \"ajouter\", \"theme\": \"REPONSES\", \"texte\": \"Préfère les tableaux\"}], \"tout_effacer\": false}\n```",
            0
        );
        $this->assertNotNull($parsed);
        $this->assertSame([['action' => 'ajouter', 'theme' => 'reponses', 'texte' => 'Préfère les tableaux']], $parsed['ops']);
        $this->assertFalse($parsed['wipe']);
    }

    public function testLAncienneFormeResteComprise(): void
    {
        $parsed = MemoryCapture::parseOps('{"memoriser": true, "faits": ["a", "  ", "b", "c", "d", "e"]}', 0);
        $this->assertNotNull($parsed);
        $this->assertSame(['a', 'b', 'c'], array_column($parsed['ops'], 'texte'));
        $this->assertSame(['autre', 'autre', 'autre'], array_column($parsed['ops'], 'theme'));

        $none = MemoryCapture::parseOps('{"memoriser": false, "faits": ["x"]}', 0);
        $this->assertSame([], $none['ops']);
    }

    public function testLesNumerosInventesOuRepetesSontEcartes(): void
    {
        $parsed = MemoryCapture::parseOps(json_encode(['operations' => [
            ['action' => 'oublier', 'numero' => 0],
            ['action' => 'oublier', 'numero' => 4],          // hors liste (3 souvenirs)
            ['action' => 'modifier', 'numero' => 2, 'texte' => ''], // texte vide
            ['action' => 'modifier', 'numero' => 2, 'texte' => 'Habite à Nantes', 'theme' => 'profil'],
            ['action' => 'oublier', 'numero' => 2],          // déjà touché
            ['action' => 'supprimer_tout', 'numero' => 1],   // action inconnue
            ['action' => 'oublier', 'numero' => '3'],
        ]]), 3);

        $this->assertSame([
            ['action' => 'modifier', 'numero' => 2, 'texte' => 'Habite à Nantes', 'theme' => 'profil'],
            ['action' => 'oublier', 'numero' => 3],
        ], $parsed['ops']);
    }

    public function testLeNombreDOperationsEstBorne(): void
    {
        $ops = [];
        for ($i = 1; $i <= 8; $i++) {
            $ops[] = ['action' => 'ajouter', 'texte' => 'Fait ' . $i];
        }
        $parsed = MemoryCapture::parseOps(json_encode(['operations' => $ops]), 0);
        $this->assertCount(MemoryCapture::MAX_OPS, $parsed['ops']);
    }

    public function testToutEffacerNEstQuUnDrapeau(): void
    {
        // « Efface ta mémoire » ne supprime rien depuis le chat : la note
        // renvoie vers Réglages → Mémoire.
        $parsed = MemoryCapture::parseOps('{"operations": [], "tout_effacer": true}', 5);
        $this->assertSame([], $parsed['ops']);
        $this->assertTrue($parsed['wipe']);
    }

    public function testUneReponseIllisibleNeChangeRien(): void
    {
        $this->assertNull(MemoryCapture::parseOps('', 0));
        $this->assertNull(MemoryCapture::parseOps('Je ne peux pas répondre.', 0));
        $this->assertNull(MemoryCapture::parseOps('{pas du json}', 0));
    }

    // ---------------------------------------------------------------------
    // Résultat, trace, note au modèle
    // ---------------------------------------------------------------------

    public function testUnResultatVideNeDeclencheNiPastilleNiNote(): void
    {
        $this->assertTrue(MemoryCapture::isEmptyResult(['faits' => [], 'modifies' => [], 'oublies' => [], 'doublons' => 0]));
        $this->assertFalse(MemoryCapture::isEmptyResult(['faits' => ['x']]));
        $this->assertFalse(MemoryCapture::isEmptyResult(['doublons' => 1]));
        $this->assertFalse(MemoryCapture::isEmptyResult(['refuses' => 1]));
        $this->assertFalse(MemoryCapture::isEmptyResult(['tout' => true]));
    }

    public function testLaTraceDitCeQuiAChange(): void
    {
        $this->assertSame(
            'Préfère le tutoiement · ✎ Habite à Nantes · − Travaille chez Durand',
            MemoryCapture::traceDetail([
                'faits'    => ['Préfère le tutoiement'],
                'modifies' => ['Habite à Nantes'],
                'oublies'  => [['content' => 'Travaille chez Durand', 'theme' => 'profil']],
            ])
        );
    }

    public function testLaNoteAuModeleDitExactementCeQuiSEstPasse(): void
    {
        $this->assertSame('', ChatController::memoryCaptureNoteFor(null));

        $note = ChatController::memoryCaptureNoteFor([
            'faits'    => ['Préfère le tutoiement'],
            'modifies' => ['Habite à Nantes'],
            'oublies'  => [['content' => 'Travaille chez Durand', 'theme' => 'profil']],
        ]);
        $this->assertStringContainsString('Mémoire — ce qui vient de se passer', $note);
        $this->assertStringContainsString("- Préfère le tutoiement", $note);
        $this->assertStringContainsString("- Habite à Nantes", $note);
        $this->assertStringContainsString("- Travaille chez Durand", $note);
        $this->assertStringContainsString('Confirme-le', $note);

        $dup = ChatController::memoryCaptureNoteFor(['faits' => [], 'doublons' => 1]);
        $this->assertStringContainsString('déjà dans sa mémoire', $dup);

        $wipe = ChatController::memoryCaptureNoteFor(['tout' => true]);
        $this->assertStringContainsString('Rien n\'a été effacé', $wipe);
        $this->assertStringContainsString('Tout effacer', $wipe);

        $refused = ChatController::memoryCaptureNoteFor(['refuses' => 1]);
        $this->assertStringContainsString('donnée sensible', $refused);

        $full = ChatController::memoryCaptureNoteFor(['plein' => true]);
        $this->assertStringContainsString((string)UserMemory::MAX_ENTRIES, $full);
        $this->assertStringContainsString('Réglages → Mémoire', $full);
    }

    public function testLeControleurPasseParLaPorteEtLeCompteur(): void
    {
        $src = file_get_contents(ROOT . '/src/Controllers/ChatController.php') ?: '';
        $this->assertStringContainsString('MemoryCapture::looksLikeRequest($content)', $src);
        $this->assertStringContainsString('UsageSource::MEMORY, self::helperBillingContext(', $src);
        $this->assertStringContainsString('UserMemory::applyExplicit(', $src);
        // Plus d'ancienne porte recopiée dans le contrôleur.
        $this->assertStringNotContainsString('function looksLikeMemoryRequest', $src);
        $this->assertStringNotContainsString('function parseMemoryExtractionJson', $src);
        // Ni capture ni injection quand l'administrateur a coupé la mémoire, et
        // jamais de capture dans un tour sans personne.
        $this->assertStringContainsString('($memoryOn && !$unattended)', $src);
        $this->assertStringContainsString('UserMemory::promptFragment((int)$user[\'id\'], $unattended)', $src);
    }
}
