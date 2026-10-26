<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Élagage (/admin/pruning) : logique pure (prédicat partagé, marqueurs,
 * normalisation des jours, confinement disque) et câblage de bout en bout.
 */
final class PruningTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = ROOT . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    // ------------------------------------------------------------------
    // 1. Prédicat SQL — source unique
    // ------------------------------------------------------------------

    /**
     * Le prédicat doit citer CHAQUE type riche : la boucle porte sur la
     * constante, donc un type ajouté à RICH_TYPES sans toucher au prédicat
     * fait tomber ce test au lieu de sortir silencieusement de l'élagage.
     */
    public function testLePredicateCiteTousLesTypesRiches(): void
    {
        $sql = Message::creationPredicateSql('m');

        foreach (Message::RICH_TYPES as $type) {
            $this->assertStringContainsString('"type":"' . $type . '"', $sql, "Type $type absent des marqueurs.");
            $this->assertStringContainsString("'" . $type . "'", $sql, "Type $type absent du IN().");
        }
        $this->assertStringContainsString('](/assets/img/generated/', $sql, 'Images générées non détectées.');
        $this->assertStringContainsString("m.role = 'assistant'", $sql, 'Le prédicat doit se limiter aux réponses.');
    }

    /** L'alias est appliqué partout : une occurrence non préfixée casserait la jointure. */
    public function testLePredicateHonoreSonAlias(): void
    {
        $sql = Message::creationPredicateSql('msg');
        $this->assertStringContainsString('msg.role', $sql);
        $this->assertStringContainsString('msg.files_json', $sql);
        $this->assertStringContainsString('msg.content', $sql);
        $this->assertStringNotContainsString(' m.', $sql, 'Alias par défaut resté en dur.');
    }

    /** La galerie et l'élagage lisent le MÊME prédicat — pas une copie. */
    public function testLaGalerieUtiliseLePredicatPartage(): void
    {
        $modele = self::lire('src/Models/Message.php');
        $this->assertStringContainsString(
            'self::creationPredicateSql(\'m\')',
            $modele,
            'creationsForUser() a reconstruit son propre prédicat.'
        );
    }

    // ------------------------------------------------------------------
    // 2. Invariant central : un marqueur n'est plus une création
    // ------------------------------------------------------------------

    /**
     * Sans ce test, une reformulation du marqueur ferait boucler l'élagage :
     * chaque exécution re-sélectionnerait les mêmes lignes et le compteur
     * d'éligibles ne tomberait jamais à zéro.
     */
    public function testUnMessageElagueNEstPlusUneCreation(): void
    {
        $ligne = [
            'content'    => Message::marqueurCreation('2026-04-12'),
            'files_json' => null,
            'file_type'  => null,
        ];

        $this->assertNull(Message::creationType($ligne));
        $this->assertNull(Message::generatedImageUrl($ligne));
        $this->assertSame([], Message::extractGeneratedImages((string)$ligne['content']));
    }

    /** Le marqueur ne doit contenir aucun littéral du prédicat SQL. */
    public function testLesMarqueursNeMatchentPasLePredicate(): void
    {
        $marqueurs = [Message::marqueurCreation('2026-04-12'), sprintf(Message::MARQUEUR_IMAGE, '12/04/2026')];

        foreach ($marqueurs as $marqueur) {
            $this->assertStringNotContainsString('](/assets/img/generated/', $marqueur);
            foreach (Message::RICH_TYPES as $type) {
                $this->assertStringNotContainsString('"type":"' . $type . '"', $marqueur);
            }
        }
    }

    public function testLeMarqueurPorteLaDateFrancaise(): void
    {
        $this->assertStringContainsString('12/04/2026', Message::marqueurCreation('2026-04-12'));
    }

    // ------------------------------------------------------------------
    // 3. neutraliserImages — la prose survit
    // ------------------------------------------------------------------

    public function testLaProseSurvitAuRetraitDesImages(): void
    {
        $contenu = "Voici deux propositions.\n\n"
            . "![Chat](/assets/img/generated/aaa.png)\n\n"
            . "La seconde est plus sobre :\n\n"
            . "![Chien](/assets/img/generated/bbb.png)\n\n"
            . "Dites-moi laquelle vous préférez.";

        $sortie = Message::neutraliserImages($contenu, '2026-04-12');

        $this->assertStringContainsString('Voici deux propositions.', $sortie);
        $this->assertStringContainsString('La seconde est plus sobre', $sortie);
        $this->assertStringContainsString('Dites-moi laquelle vous préférez.', $sortie);
        $this->assertStringNotContainsString('/assets/img/generated/', $sortie);
        $this->assertSame(2, substr_count($sortie, '🗑 Image élaguée le 12/04/2026'));
    }

    public function testUnContenuSansImageResteIntact(): void
    {
        $contenu = "Réponse purement textuelle, avec un lien https://exemple.fr et du `code`.";
        $this->assertSame($contenu, Message::neutraliserImages($contenu, '2026-04-12'));
    }

    /** Rejouée, l'opération ne change plus rien : l'élagage est relançable. */
    public function testLeRetraitDesImagesEstIdempotent(): void
    {
        $contenu = "Texte.\n\n![Chat](/assets/img/generated/aaa.png)";
        $une     = Message::neutraliserImages($contenu, '2026-04-12');
        $deux    = Message::neutraliserImages($une, '2026-04-12');
        $this->assertSame($une, $deux);
    }

    /** Une image tierce n'est pas notre affaire : elle n'est jamais touchée. */
    public function testUneImageExterneNEstPasTouchee(): void
    {
        $contenu = '![Logo](https://exemple.fr/logo.png) et ![Local](/assets/img/generated/aaa.png)';
        $sortie  = Message::neutraliserImages($contenu, '2026-04-12');
        $this->assertStringContainsString('https://exemple.fr/logo.png', $sortie);
        $this->assertStringNotContainsString('/assets/img/generated/', $sortie);
    }

    // ------------------------------------------------------------------
    // 4. joursValides — jamais « tout élaguer » par accident
    // ------------------------------------------------------------------

    /**
     * `''` et `'0'` retombent sur le DÉFAUT et non sur 0 : un champ vidé par
     * mégarde ne doit pas devenir « supprime tout ce qui a plus de 0 jour ».
     */
    public function testUnSeuilVideOuNulRetombeSurLeDefaut(): void
    {
        $this->assertSame(PruningService::JOURS_DEFAUT, PruningService::joursValides(''));
        $this->assertSame(PruningService::JOURS_DEFAUT, PruningService::joursValides('0'));
        $this->assertSame(PruningService::JOURS_DEFAUT, PruningService::joursValides(0));
        $this->assertSame(PruningService::JOURS_DEFAUT, PruningService::joursValides(null));
        $this->assertSame(PruningService::JOURS_DEFAUT, PruningService::joursValides('abc'));
    }

    public function testLeSeuilEstBorne(): void
    {
        $this->assertSame(PruningService::JOURS_MIN, PruningService::joursValides('-5'));
        $this->assertSame(PruningService::JOURS_MIN, PruningService::joursValides(1));
        $this->assertSame(PruningService::JOURS_MAX, PruningService::joursValides('99999'));
        $this->assertSame(30, PruningService::joursValides('30'));
        $this->assertSame(120, PruningService::JOURS_DEFAUT);
    }

    public function testLeSeuilProduitUneDatePassee(): void
    {
        $seuil = PruningService::seuil(120);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $seuil);
        $this->assertLessThan(time(), strtotime($seuil));
    }

    // ------------------------------------------------------------------
    // 5. MediaFile — confinement
    // ------------------------------------------------------------------

    public function testLaTraverseeEstRefusee(): void
    {
        $base = sys_get_temp_dir() . '/mia-pruning-' . bin2hex(random_bytes(4));
        @mkdir($base, 0775, true);

        try {
            $this->assertFalse(MediaFile::estConfine($base . '/../../etc/passwd', $base));
            $this->assertFalse(MediaFile::estConfine('/etc/passwd', $base));
            $this->assertFalse(MediaFile::estConfine($base, $base), 'Le dossier lui-même n\'est pas un enfant.');

            $fichier = $base . '/vrai.png';
            file_put_contents($fichier, 'x');
            $this->assertTrue(MediaFile::estConfine($fichier, $base));
        } finally {
            @unlink($base . '/vrai.png');
            @rmdir($base);
        }
    }

    /** Un lien symbolique sortant est rejeté : realpath le résout avant comparaison. */
    public function testUnLienSymboliqueSortantEstRefuse(): void
    {
        $base = sys_get_temp_dir() . '/mia-pruning-' . bin2hex(random_bytes(4));
        $hors = sys_get_temp_dir() . '/mia-hors-' . bin2hex(random_bytes(4));
        @mkdir($base, 0775, true);
        file_put_contents($hors, 'secret');

        try {
            if (!@symlink($hors, $base . '/piege.png')) {
                $this->markTestSkipped('Liens symboliques indisponibles sur ce système de fichiers.');
            }
            $this->assertFalse(MediaFile::estConfine($base . '/piege.png', $base));
            $this->assertSame(0, MediaFile::supprimerDans($base . '/piege.png', $base));
            $this->assertFileExists($hors, 'La cible du lien a été supprimée.');
        } finally {
            @unlink($base . '/piege.png');
            @unlink($hors);
            @rmdir($base);
        }
    }

    public function testLaSuppressionRendLesOctetsLiberes(): void
    {
        $base = sys_get_temp_dir() . '/mia-pruning-' . bin2hex(random_bytes(4));
        @mkdir($base, 0775, true);
        $fichier = $base . '/media.png';
        file_put_contents($fichier, str_repeat('x', 1234));

        try {
            $this->assertSame(1234, MediaFile::supprimerDans($fichier, $base));
            $this->assertFileDoesNotExist($fichier);
            $this->assertSame(0, MediaFile::supprimerDans($fichier, $base), 'Un fichier absent ne libère rien.');
        } finally {
            @unlink($fichier);
            @rmdir($base);
        }
    }

    /** Les deux conventions de `path` de files_json sont distinguées. */
    public function testLesDeuxConventionsDeCheminSontResolues(): void
    {
        // URL web (vidéo générée)
        $this->assertSame(
            ROOT . '/public/assets/video/generated/v.mp4',
            MediaFile::resoudre('/assets/video/generated/v.mp4')
        );
        // Chemin de FS absolu (pièce jointe utilisateur)
        $this->assertSame(
            ROOT . '/storage/uploads/a1b2.pdf',
            MediaFile::resoudre(ROOT . '/storage/uploads/a1b2.pdf')
        );
    }

    public function testUneReferenceHorsPerimetreNEstPasResolue(): void
    {
        $this->assertNull(MediaFile::resoudre(''));
        $this->assertNull(MediaFile::resoudre('https://exemple.fr/image.png'));
        $this->assertNull(MediaFile::resoudre('/etc/passwd'));
        $this->assertNull(MediaFile::resoudre('/assets/img/logo.png'), 'Le logo n\'est pas un média élaguable.');
        $this->assertNull(
            MediaFile::resoudre(ROOT . '/storage/uploads/../../config/app.php'),
            'Un `..` doit être refusé même s\'il commence par une racine autorisée.'
        );
    }

    // ------------------------------------------------------------------
    // 6. Périmètre du balayage
    // ------------------------------------------------------------------

    /**
     * Les médias générés sont balayés, MAIS jamais les documents des bases de
     * connaissances ni ceux des assistants : ils sont référencés par d'autres
     * tables que `messages`, et l'index de références de l'élagage les
     * effacerait intégralement.
     */
    public function testLeBalayageEpargneLesDocumentsDesBasesEtAssistants(): void
    {
        foreach (StorageInfo::GENERATED_DIRS as $dossier) {
            $this->assertContains($dossier, PruningService::DOSSIERS_BALAYES, "$dossier devrait être balayé.");
        }

        $this->assertContains('/storage/uploads', PruningService::DOSSIERS_BALAYES);
        $this->assertNotContains('/storage/knowledge_bases', PruningService::DOSSIERS_BALAYES);
        $this->assertNotContains('/storage/profile_documents', PruningService::DOSSIERS_BALAYES);
    }

    /** Tout dossier balayé doit être une racine de suppression autorisée. */
    public function testLesDossiersBalayesSontDesRacinesAutorisees(): void
    {
        foreach (PruningService::DOSSIERS_BALAYES as $dossier) {
            $this->assertContains($dossier, MediaFile::RACINES, "$dossier n'est pas une racine de MediaFile.");
        }
    }

    /** La garde d'âge protège les générations en cours : jamais moins d'une journée. */
    public function testLaGardeDAgeCouvreAuMoinsUneJournee(): void
    {
        $this->assertGreaterThanOrEqual(86400, PruningService::AGE_MIN_ORPHELIN_SEC);
    }

    // ------------------------------------------------------------------
    // 7. Câblage de bout en bout
    // ------------------------------------------------------------------

    public function testLaPageEstBrancheeDeBoutEnBout(): void
    {
        $index = self::lire('public/index.php');

        foreach ([
            "get('/admin/pruning'",
            "post('/admin/pruning/settings'",
            "post('/admin/pruning/conversations'",
            "post('/admin/pruning/creations'",
            "post('/admin/pruning/orphans'",
        ] as $route) {
            $this->assertStringContainsString($route, $index, "Route absente : $route");
        }
        $this->assertStringContainsString('PruningController::class', $index);

        $controleur = self::lire('src/Controllers/PruningController.php');
        foreach (['index', 'saveSettings', 'pruneConversations', 'pruneCreations', 'sweepOrphans'] as $methode) {
            $this->assertStringContainsString("public function $methode(", $controleur, "Méthode $methode absente.");
        }
    }

    /** La page est admin only : la nav n'est qu'un affichage, la garde est serveur. */
    public function testChaqueMethodeExigeUnAdmin(): void
    {
        $controleur = self::lire('src/Controllers/PruningController.php');
        $this->assertSame(
            5,
            substr_count($controleur, 'AuthMiddleware::requireAdmin();'),
            'Les cinq méthodes doivent porter la garde admin.'
        );
    }

    /** La confirmation est doublée côté serveur : `onsubmit` ne protège d'aucun POST forgé. */
    public function testLaConfirmationEstVerifieeCoteServeur(): void
    {
        $controleur = self::lire('src/Controllers/PruningController.php');
        $this->assertStringContainsString("self::MOT_CONFIRMATION", $controleur);
        $this->assertStringContainsString("'ELAGUER'", $controleur);
        $this->assertStringContainsString("strtoupper(trim((string)(\$_POST['confirm'] ?? '')))", $controleur);

        $gabarit = self::lire('templates/admin/pruning.php');
        $this->assertSame(3, substr_count($gabarit, "toUpperCase() === 'ELAGUER'"), 'Trois actions, trois gardes.');
    }

    /**
     * L'élagage ferme la session avant son travail long : sans un
     * Session::start() avant le flash, le rapport serait écrit dans un $_SESSION
     * que plus personne ne persiste et l'admin verrait la page revenir muette,
     * comme si rien ne s'était passé.
     */
    public function testLeRapportEstEcritDansUneSessionRouverte(): void
    {
        $controleur = self::lire('src/Controllers/PruningController.php');

        $this->assertMatchesRegularExpression(
            '/private function repondre\([^)]*\): never\s*\{\s*Session::start\(\);\s*Session::flash\(/',
            $controleur,
            'Session::start() doit précéder immédiatement Session::flash() dans repondre().'
        );

        // Le service ferme bien la session : c'est ce qui rend la garde nécessaire.
        $this->assertStringContainsString(
            'session_write_close();',
            self::lire('src/Maintenance/PruningService.php')
        );
    }

    /**
     * L'élagage est un onglet de « Maintenance → Données » (avec la
     * sauvegarde) : atteignable par la barre d'onglets, et le menu s'allume
     * sur la page — sans quoi Maintenance resterait repliée.
     */
    public function testLaPageEstAtteignableParDonneesEtSAllume(): void
    {
        $this->assertSame(['/admin/pruning', 'Élagage'], AdminTabs::onglets(AdminTabs::DONNEES)['pruning'] ?? null);
        $nav = self::lire('templates/partials/admin-nav.php');
        $this->assertStringContainsString('$dataActive = in_array($page, AdminTabs::pages(AdminTabs::DONNEES), true);', $nav);
        $this->assertStringContainsString("\$navCurrent = \$diagActive ? 'diagnostics' : (\$dataActive ? 'backup' : \$page);", $nav, 'Sur l’élagage, « Données » s’allume et sa rubrique s’ouvre.');
        $this->assertContains('backup', AdminAccess::ENTRY_PAGES['maintenance']);
        $this->assertStringContainsString("\$page = 'pruning'", self::lire('templates/admin/pruning.php'));
        $this->assertStringContainsString('AdminTabs::DONNEES', self::lire('templates/admin/pruning.php'));
    }

    public function testLesClassesSontChargees(): void
    {
        $classes   = self::lire('config/classes.php');
        $bootstrap = self::lire('tests/bootstrap.php');

        foreach (['/src/Helpers/MediaFile.php', '/src/Maintenance/PruningService.php'] as $fichier) {
            $this->assertStringContainsString($fichier, $classes, "$fichier absent de config/classes.php");
            $this->assertStringContainsString($fichier, $bootstrap, "$fichier absent de tests/bootstrap.php");
        }
        $this->assertStringContainsString('/src/Controllers/PruningController.php', $classes);
    }

    /** La garde anti-traversée ne doit exister qu'à UN endroit. */
    public function testLaGardeDeConfinementNEstPasDupliquee(): void
    {
        $admin = self::lire('src/Controllers/AdminController.php');
        $this->assertStringContainsString('MediaFile::supprimerAsset', $admin);
        $this->assertStringNotContainsString(
            'str_starts_with($fullPath, $basePath',
            $admin,
            'AdminController porte encore sa propre garde de confinement.'
        );
    }

    // ------------------------------------------------------------------
    // 8. Contrat du gabarit
    // ------------------------------------------------------------------

    public function testLeGabaritPoseSaPage(): void
    {
        $gabarit = self::lire('templates/admin/pruning.php');
        $this->assertStringContainsString("\$page = 'pruning'", $gabarit);
        $this->assertStringContainsString('csrf-token', $gabarit, 'Le formulaire auto-enregistré en a besoin.');
        $this->assertStringContainsString('autosave-hint.php', $gabarit);
    }

    /**
     * SEUL le formulaire des réglages est auto-enregistré : les trois actions
     * destructives gardent un bouton et une confirmation explicites.
     */
    public function testSeulLesReglagesSontAutoEnregistres(): void
    {
        $gabarit = self::lire('templates/admin/pruning.php');
        $this->assertSame(1, substr_count($gabarit, 'data-autosave>'), 'Un seul formulaire doit être armé.');
        $this->assertStringContainsString('id="pruningSettings"', $gabarit);

        // Les trois formulaires destructifs : présents, et AUCUN armé.
        foreach (['conversations', 'creations', 'orphans'] as $action) {
            $this->assertMatchesRegularExpression(
                '#<form[^>]*action="/admin/pruning/' . $action . '"(?![^>]*data-autosave)[^>]*>#',
                $gabarit,
                "Le formulaire $action est absent ou armé en auto-enregistrement."
            );
        }
    }

    /**
     * Le piège central de cette page : jours auto-enregistrés + bouton
     * destructif. Sans settle(), le serveur élaguerait au seuil STOCKÉ, pas à
     * celui affiché. Sans detach(), un envoi différé reposterait l'état d'avant.
     */
    public function testLeGabaritVideLaFileAutosaveAvantDElaguer(): void
    {
        $gabarit = self::lire('templates/admin/pruning.php');
        $this->assertStringContainsString('MiaAutosave.settle(reglages)', $gabarit);
        $this->assertStringContainsString('MiaAutosave.detach(reglages)', $gabarit);
        $this->assertStringContainsString('MiaAutosave.isDirty(reglages)', $gabarit);
    }

    /** L'écran doit dire que MySQL ne rend pas l'espace : sinon, ticket garanti. */
    public function testLeGabaritExpliqueQueMysqlNeRendPasLEspace(): void
    {
        $gabarit = self::lire('templates/admin/pruning.php');
        $this->assertStringContainsString('ne diminue pas', $gabarit);
    }

    /** « Favori » n'existe pas en base : la page doit dire que c'est l'épinglage. */
    public function testLeGabaritExpliqueCeQuEstUnFavori(): void
    {
        $gabarit = self::lire('templates/admin/pruning.php');
        $this->assertStringContainsString('épinglée', $gabarit);
        $this->assertStringContainsString('Conversation::MAX_PINNED', $gabarit);
    }

    /**
     * La sauvegarde de l'application ne couvre PAS les conversations : promettre
     * un filet inexistant serait pire que ne rien dire.
     */
    public function testLeGabaritNeRenvoiePasVersUneSauvegardeQuiNExistePas(): void
    {
        $gabarit = self::lire('templates/admin/pruning.php');
        $this->assertStringContainsString('sauvegarde MySQL', $gabarit);
        $this->assertStringNotContainsString(
            'Exportez d\'abord une sauvegarde',
            $gabarit,
            'BackupService::EXCLUDED_TABLES exclut conversations/messages : ce conseil serait faux.'
        );
    }
}
