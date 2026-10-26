<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Catalogue des droits (src/Auth/Permissions.php), pur : forme du catalogue,
 * défauts des rôles livrés (ils reproduisent EXACTEMENT les droits d'avant
 * l'écran des rôles — passer à la 1.5 ne change les droits de personne),
 * fermeture des implications, lecture d'un formulaire, stockage.
 *
 * Et une garde de câblage : tout droit nommé dans le code (requirePermission,
 * Session::can, Role::userCan, Role::grants, ScheduledTask/Workflow::allows)
 * existe au catalogue. Une faute de frappe dans une clé refuserait en silence
 * l'accès à tout le monde sauf aux administrateurs — aucun autre test ne la
 * verrait.
 */
final class PermissionsTest extends TestCase
{
    private static function racine(): string
    {
        return dirname(__DIR__, 2);
    }

    // ------------------------------------------------------------------
    // Forme du catalogue
    // ------------------------------------------------------------------

    public function testChaqueFonctionnaliteEstCompleteEtCoherente(): void
    {
        foreach (Permissions::FEATURES as $feature => $def) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $feature);
            $this->assertArrayHasKey($def['section'], Permissions::SECTIONS, $feature);
            $this->assertNotSame('', trim($def['label']), $feature);
            $this->assertNotSame('', trim($def['summary']), $feature);
            $this->assertNotSame([], $def['actions'], $feature);
            foreach ($def['actions'] as $action => $phrase) {
                $this->assertContains($action, Permissions::ACTIONS, "$feature : action inconnue $action");
                $this->assertNotSame('', trim($phrase), "$feature.$action : la case doit dire ce qu'elle ouvre");
            }
            $this->assertArrayNotHasKey('scope', $def, "$feature : la portée est devenue la colonne « Gérer ».");
            foreach ($def['requires'] as $action => $needed) {
                $this->assertTrue(isset($def['actions'][$action]), "$feature : implication sur une action non déclarée ($action)");
                foreach ($needed as $n) {
                    $this->assertArrayHasKey($n, $def['actions'], "$feature.$action entraîne une action non déclarée ($n)");
                    $this->assertNotSame($action, $n);
                }
            }
        }
        $this->assertSame(['view', 'use', 'create', 'edit', 'delete', 'manage'], Permissions::ACTIONS);
        $this->assertSame(Permissions::ACTIONS, array_keys(Permissions::ACTION_LABELS));
    }

    public function testLeCatalogueCouvreLesPossibilitesDeMia(): void
    {
        // Ce que l'écran doit permettre de régler (demande produit) : tâches
        // programmées, assistants, workflows, utilisateurs, groupes, LLM,
        // outils, SSO, connecteurs — et le reste de l'administration.
        foreach (['assistants', 'knowledge_bases', 'schedules', 'workflows', 'tools', 'connectors',
                  'dashboard', 'users', 'groups', 'llms', 'sso', 'costs', 'company', 'diagnostics'] as $feature) {
            $this->assertArrayHasKey($feature, Permissions::FEATURES);
        }
        // Les rôles et les données ne se délèguent pas.
        $this->assertSame(['roles', 'data'], array_keys(Permissions::ADMIN_ONLY));
        foreach (['roles', 'backup', 'pruning'] as $page) {
            $this->assertNull(AdminAccess::PAGES[$page][0], "$page doit rester réservée aux administrateurs");
        }
        foreach (Permissions::allKeys() as $key) {
            $this->assertStringStartsNotWith('roles.', $key);
            $this->assertStringStartsNotWith('data.', $key);
        }
    }

    public function testToutesLesClesSontUniquesEtConnues(): void
    {
        $keys = Permissions::allKeys();
        $this->assertSame(array_values(array_unique($keys)), $keys);
        $attendu = 0;
        foreach (Permissions::FEATURES as $def) {
            $attendu += count($def['actions']);
        }
        $this->assertCount($attendu, $keys);
        foreach ($keys as $key) {
            $this->assertTrue(Permissions::isKnown($key), $key);
        }
        $this->assertFalse(Permissions::isKnown('tools.manage'), 'Les outils appartiennent à l’organisation : rien à gérer pour les autres.');
        $this->assertFalse(Permissions::isKnown('assistants.all'), 'L’ancienne portée n’est plus un droit : elle est relue en « Gérer ».');
        $this->assertFalse(Permissions::isKnown('connectors.create'), 'Action non déclarée.');
        $this->assertFalse(Permissions::isKnown('roles.view'));
        $this->assertFalse(Permissions::isKnown('assistants'));
        $this->assertFalse(Permissions::isKnown(''));
    }

    // ------------------------------------------------------------------
    // Défauts des rôles livrés
    // ------------------------------------------------------------------

    public function testLesDefautsReproduisentLesDroitsDAvantLEcranDesRoles(): void
    {
        // Utilisateur : discute (assistants de ses groupes, bases partagées,
        // outils, connecteurs) et programme ses tâches. Rien d'autre.
        $this->assertSame([
            'assistants.use',
            'knowledge_bases.use',
            'schedules.view', 'schedules.use', 'schedules.create', 'schedules.edit', 'schedules.delete',
            'tools.use',
            'connectors.use',
        ], Permissions::defaultsFor('user'));

        // Créateur : tout ce que fait l'utilisateur, plus ses assistants, ses
        // bases et ses workflows — sans portée « toute l'organisation » ni écran
        // d'administration.
        $creator = Permissions::defaultsFor('creator');
        $this->assertTrue(Permissions::isSubset(Permissions::defaultsFor('user'), $creator));
        foreach (['assistants', 'knowledge_bases', 'workflows'] as $feature) {
            foreach (['view', 'use', 'create', 'edit', 'delete'] as $action) {
                $this->assertContains("$feature.$action", $creator);
            }
        }
        foreach (array_merge(Permissions::defaultsFor('user'), $creator) as $key) {
            [$feature] = explode('.', $key, 2);
            $this->assertSame(Permissions::SECTION_FEATURES, Permissions::FEATURES[$feature]['section'], "$key : aucun écran d'administration par défaut");
            $this->assertStringEndsNotWith('.manage', $key, 'Gérer ceux des autres reste à l’administrateur.');
        }
        $this->assertNotContains('workflows.view', Permissions::defaultsFor('user'), 'Les workflows restaient aux créateurs.');

        // Administrateur : tout, sans liste à tenir.
        $this->assertSame(Permissions::allKeys(), Permissions::defaultsFor('admin'));
        $this->assertNull(Permissions::defaultsFor('responsable-rh'));

        // Les défauts déclarés sont déjà fermés : rien n'y est ajouté en silence.
        foreach (Permissions::DEFAULTS as $role => $keys) {
            $this->assertSame(Permissions::close($keys), Permissions::defaultsFor($role), $role);
        }
    }

    // ------------------------------------------------------------------
    // Fermeture, formulaire, stockage
    // ------------------------------------------------------------------

    public function testLaFermetureAjouteCeQueChaqueDroitSuppose(): void
    {
        $this->assertSame(['assistants.view', 'assistants.edit'], Permissions::close(['assistants.edit']));
        // Gérer suppose Modifier, qui suppose Voir.
        $this->assertSame(['assistants.view', 'assistants.edit', 'assistants.manage'], Permissions::close(['assistants.manage']));
        $this->assertSame(['knowledge_bases.view', 'knowledge_bases.edit', 'knowledge_bases.manage'], Permissions::close(['knowledge_bases.manage']));
        // Deux crans : supprimer → utiliser → voir.
        $this->assertSame(['schedules.view', 'schedules.use', 'schedules.delete'], Permissions::close(['schedules.delete']));
        $this->assertSame(['workflows.view', 'workflows.use', 'workflows.create'], Permissions::close(['workflows.create']));
        // Utiliser un outil n'oblige pas à ouvrir la page d'administration.
        $this->assertSame(['tools.use'], Permissions::close(['tools.use']));
        $this->assertSame(['llms.view', 'llms.use'], Permissions::close(['llms.use']));
        // Inconnus écartés, doublons fusionnés, ordre du catalogue.
        $this->assertSame(['assistants.use', 'users.view'], Permissions::close(['users.view', 'nimporte.quoi', 'assistants.use', 'users.view', ' ']));
        $this->assertSame([], Permissions::close([]));
    }

    // ------------------------------------------------------------------
    // Sens des colonnes : ce que disent la légende de l'écran et le wiki est vrai
    // ------------------------------------------------------------------

    public function testChaqueColonneAUneDefinitionQueLeCatalogueConfirme(): void
    {
        $this->assertSame(Permissions::ACTIONS, array_keys(Permissions::ACTION_DEFINITIONS));
        foreach (Permissions::ACTION_DEFINITIONS as $action => $definition) {
            $this->assertNotSame('', trim($definition), $action);
        }
        // « Voir » = voir la fonctionnalité apparaître (menu, page en lecture) ;
        // « Utiliser » ne règle rien.
        $this->assertStringStartsWith('Voir la fonctionnalité apparaître', Permissions::ACTION_DEFINITIONS[Permissions::VIEW]);
        $this->assertStringContainsString('en lecture seule', Permissions::ACTION_DEFINITIONS[Permissions::VIEW]);
        $this->assertStringContainsString('sans rien régler', Permissions::ACTION_DEFINITIONS[Permissions::USE]);
        // Chaque case « Voir » dit ce qui apparaît, et en lecture seule.
        foreach (Permissions::FEATURES as $feature => $def) {
            if (isset($def['actions'][Permissions::VIEW])) {
                $this->assertMatchesRegularExpression('/apparaît|apparaissent/u', $def['actions'][Permissions::VIEW], "$feature.view : ce qui apparaît");
                if (array_intersect(array_keys($def['actions']), [Permissions::CREATE, Permissions::EDIT, Permissions::DELETE]) !== []) {
                    $this->assertStringContainsString('lecture seule', $def['actions'][Permissions::VIEW], "$feature.view : en lecture seule");
                }
            }
        }
        // « Gérer » suppose « Modifier » : la fermeture le confirme.
        $this->assertStringContainsString('Suppose « Modifier »', Permissions::ACTION_DEFINITIONS[Permissions::MANAGE]);
        foreach (Permissions::FEATURES as $feature => $def) {
            if (isset($def['actions'][Permissions::MANAGE])) {
                $this->assertContains("$feature.edit", Permissions::close(["$feature.manage"]), "$feature.manage doit supposer « Modifier »");
            }
        }
        // « Créer », « Modifier », « Supprimer » disent supposer « Voir » : la
        // fermeture le confirme sur chaque ligne qui les propose.
        foreach ([Permissions::CREATE, Permissions::EDIT, Permissions::DELETE] as $action) {
            $this->assertStringContainsString('Suppose « Voir »', Permissions::ACTION_DEFINITIONS[$action], $action);
            foreach (Permissions::FEATURES as $feature => $def) {
                if (isset($def['actions'][$action])) {
                    $this->assertContains("$feature.view", Permissions::close(["$feature.$action"]), "$feature.$action doit supposer « Voir »");
                }
            }
        }
    }

    public function testVoirEtUtiliserSontIndependantsSaufCeQuiSeLanceDeSaPage(): void
    {
        // La note énumère EXACTEMENT les lignes où « Utiliser » suppose « Voir ».
        $avecPage = [];
        foreach (Permissions::FEATURES as $feature => $def) {
            if (isset($def['actions'][Permissions::USE])
                && in_array("$feature.view", Permissions::close(["$feature.use"]), true)) {
                $avecPage[] = $feature;
            }
        }
        $this->assertSame(['schedules', 'workflows', 'llms', 'diagnostics'], $avecPage,
            'Une ligne dont « Utiliser » suppose (ou ne suppose plus) « Voir » : mettre VIEW_USE_NOTE et le wiki à jour.');
        foreach (['tâches programmées', 'workflows', 'tests des modèles', 'réparations du diagnostic'] as $cite) {
            $this->assertStringContainsString($cite, Permissions::VIEW_USE_NOTE);
        }
        // L'exemple de la note est vrai : le rôle Utilisateur discute avec les
        // assistants sans ouvrir la page Assistants.
        $this->assertContains('assistants.use', Permissions::DEFAULTS[Role::USER]);
        $this->assertNotContains('assistants.view', Permissions::DEFAULTS[Role::USER]);
        // Sur les lignes servies dans la conversation, la case « Voir » le rappelle.
        foreach (['assistants', 'knowledge_bases', 'tools', 'connectors'] as $feature) {
            $this->assertNotContains("$feature.view", Permissions::close(["$feature.use"]), $feature);
            $this->assertStringContainsString('Inutile pour', Permissions::FEATURES[$feature]['actions'][Permissions::VIEW], $feature);
        }
    }

    public function testTachesEtWorkflowsSontDitsPropresAChacun(): void
    {
        // Pas de « Gérer » : les modèles filtrent par compte
        // (Workflow::forUser, ScheduledTask::forUser), même pour un
        // administrateur — l'écran (case sans objet expliquée) et le wiki le disent.
        foreach (['schedules', 'workflows'] as $feature) {
            $this->assertFalse(Permissions::isKnown("$feature.manage"), $feature);
            $this->assertStringContainsString('Propres à chaque personne', Permissions::FEATURES[$feature]['summary'], $feature);
            $this->assertStringContainsString('Propre à chaque personne', Permissions::naReason($feature, 'manage'), $feature);
        }
        $wiki = (string)file_get_contents(self::racine() . '/docs/user/admin/utilisateurs/gerer-les-roles.md');
        $this->assertStringContainsString('ils sont propres à chaque personne', $wiki);
        $guide = (string)file_get_contents(self::racine() . '/docs/GUIDE-ADMIN.md');
        $this->assertStringContainsString('Les tâches programmées et les workflows sont propres à chaque personne', $guide);
    }

    public function testLeWikiEtLeGuideReprennentLesDefinitions(): void
    {
        // Le wiki écrit l'apostrophe droite : seule différence admise.
        $plat = static fn(string $t): string => str_replace('’', "'", $t);
        $wiki = $plat((string)file_get_contents(self::racine() . '/docs/user/admin/utilisateurs/gerer-les-roles.md'));
        foreach (Permissions::ACTIONS as $action) {
            $label = Permissions::ACTION_LABELS[$action];
            $this->assertStringContainsString(
                '| **' . $label . '** | ' . $plat(Permissions::ACTION_DEFINITIONS[$action]) . ' |',
                $wiki,
                "Wiki : la définition de « $label » doit être celle de l'écran, mot pour mot."
            );
        }
        $this->assertStringContainsString($plat(Permissions::VIEW_USE_NOTE), $wiki, 'Wiki : la note Voir / Utiliser, mot pour mot.');
        // La carte des habilitations a disparu : le wiki n'en parle plus.
        $this->assertStringNotContainsString('Vue d\'ensemble', $wiki);
        $this->assertStringNotContainsString('carte des habilitations', mb_strtolower($wiki));

        $guide = mb_strtolower($plat((string)file_get_contents(self::racine() . '/docs/GUIDE-ADMIN.md')));
        $this->assertStringNotContainsString('carte des habilitations', $guide);
        foreach ([Permissions::VIEW, Permissions::USE] as $action) {
            $this->assertStringContainsString(
                rtrim(mb_strtolower($plat(Permissions::ACTION_DEFINITIONS[$action])), '.'),
                $guide,
                'Guide administrateur : définition de « ' . Permissions::ACTION_LABELS[$action] . ' ».'
            );
        }
        $this->assertStringContainsString('sont indépendants pour ce qui sert dans la conversation', $guide);
    }

    // ------------------------------------------------------------------
    // Cases sans objet : chacune dit pourquoi
    // ------------------------------------------------------------------

    public function testChaqueCaseSansObjetDitPourquoi(): void
    {
        foreach (Permissions::FEATURES as $feature => $def) {
            foreach (Permissions::ACTIONS as $action) {
                if (isset($def['actions'][$action])) {
                    continue;
                }
                $raison = Permissions::naReason($feature, $action);
                $this->assertNotSame('', trim($raison), "$feature.$action");
                $this->assertNotSame(Permissions::NA_REASONS[''], $raison, "$feature.$action : une raison précise, pas la phrase commune");
            }
        }
        // « Gérer » ne vaut que pour ce qui se partage.
        $this->assertSame(['assistants', 'knowledge_bases'], array_values(array_filter(
            array_keys(Permissions::FEATURES),
            static fn(string $f): bool => Permissions::isKnown("$f.manage")
        )));
        $this->assertStringContainsString('appartiennent à l’organisation', Permissions::naReason('tools', 'manage'));
        $this->assertStringContainsString('Propre à chaque personne', Permissions::naReason('connectors', 'manage'));
        $this->assertStringContainsString('livrés avec MIA', Permissions::naReason('connectors', 'create'));
        $this->assertStringContainsString('ne vaut que pour ce qui se partage', Permissions::naReason('users', 'manage'));
        $this->assertSame(['Modifier'], Permissions::requiredLabels('assistants', 'manage'));
        $this->assertSame(['Utiliser'], Permissions::requiredLabels('schedules', 'create'));
        $this->assertSame([], Permissions::requiredLabels('assistants', 'use'));
    }

    public function testLeFormulaireSeLitSousSesDeuxFormes(): void
    {
        $this->assertSame(
            ['assistants.view', 'assistants.use', 'assistants.edit', 'assistants.manage', 'users.view'],
            Permissions::fromPost(['assistants' => ['use', 'manage'], 'users' => ['view']])
        );
        // La forme plate donne le même ensemble.
        $this->assertSame(Permissions::fromPost(['assistants.edit']), Permissions::fromPost(['assistants' => ['edit']]));
        // Rien de forgé ne passe : ni les domaines réservés, ni une action
        // inventée, ni l'ancienne portée, ni « Gérer » là où il est sans objet.
        $this->assertSame([], Permissions::fromPost(['roles' => ['view'], 'data' => ['delete'], 'assistants' => ['admin', 'tout', 'all'], 'tools' => ['manage']]));
        $this->assertSame([], Permissions::fromPost('assistants.view'));
        $this->assertSame([], Permissions::fromPost(null));
    }

    public function testLeStockageGardeChaqueDroitEtSuitLesVersionsFutures(): void
    {
        $keys = ['assistants.view', 'assistants.use', 'users.view'];
        $json = Permissions::encode($keys);
        $map  = json_decode($json, true);
        $this->assertSame(Permissions::allKeys(), array_keys($map), 'Carte de TOUS les droits connus.');
        $this->assertTrue($map['users.view']);
        $this->assertFalse($map['users.edit']);
        $this->assertSame($keys, Permissions::decode($json));

        // Un droit absent de la carte (ajouté par une version future) prend le
        // repli : le défaut d'un rôle livré, rien pour un rôle personnalisé.
        unset($map['tools.use']);
        $partiel = (string)json_encode($map);
        $this->assertContains('tools.use', Permissions::decode($partiel, ['tools.use']));
        $this->assertNotContains('tools.use', Permissions::decode($partiel, []));
        // Une carte illisible : le repli tel quel (fermé).
        $this->assertSame(['assistants.view', 'assistants.edit'], Permissions::decode('{pas du json', ['assistants.edit']));
        $this->assertSame(Permissions::defaultsFor('user'), Permissions::decode(null, Permissions::defaultsFor('user')));
        // Une carte qui coche « Modifier » sans « Voir » est refermée.
        $this->assertSame(['users.view', 'users.edit'], Permissions::decode((string)json_encode(['users.edit' => true])));
    }

    public function testUneCarteEnregistreeAvantLaColonneGererNeDonneRienDePlus(): void
    {
        // Carte d'avant : TOUS les anciens droits, la portée sous `.all`.
        $ancienne = static function (array $vrais): string {
            $map = [];
            foreach (Permissions::allKeys() as $k) {
                if (!str_ends_with($k, '.manage')) {
                    $map[$k] = in_array($k, $vrais, true);
                }
            }
            $map['assistants.all'] = in_array('assistants.all', $vrais, true);
            $map['knowledge_bases.all'] = in_array('knowledge_bases.all', $vrais, true);
            return (string)json_encode($map);
        };
        // Portée + Modifier : la portée devient « Gérer », rien d'autre ne bouge.
        $avant = ['assistants.use', 'assistants.view', 'assistants.edit', 'assistants.all', 'knowledge_bases.use'];
        $this->assertSame(
            ['assistants.view', 'assistants.use', 'assistants.edit', 'assistants.manage', 'knowledge_bases.use'],
            Permissions::decode($ancienne($avant))
        );
        // Portée SANS Modifier (lecture de tout) : « Gérer » supposerait
        // « Modifier » — le rôle n'en gagne pas, la portée tombe.
        $lecture = Permissions::decode($ancienne(['knowledge_bases.view', 'knowledge_bases.all']));
        $this->assertSame(['knowledge_bases.view'], $lecture);
        $this->assertNotContains('knowledge_bases.edit', $lecture);
        // Sans portée : rien de plus, même avec un repli qui l'accorderait.
        $this->assertSame(['assistants.view', 'assistants.edit'], Permissions::decode($ancienne(['assistants.view', 'assistants.edit']), ['assistants.manage']));
        // Rôle livré relu avec ses défauts : la carte fait foi.
        $creator = Permissions::defaultsFor('creator');
        $this->assertSame($creator, Permissions::decode($ancienne(array_merge($creator)), $creator));
        // Une carte d'après « Gérer » ignore l'ancienne clé.
        $this->assertSame(['assistants.view'], Permissions::decode((string)json_encode(['assistants.view' => true, 'assistants.manage' => false, 'assistants.all' => true])));
        // Une fois réenregistrée, plus d'ancienne clé.
        $this->assertArrayNotHasKey('assistants.all', json_decode(Permissions::encode(['assistants.manage']), true));
    }

    public function testInclusionDifferenceResumeEtLibelles(): void
    {
        $this->assertTrue(Permissions::isSubset(['users.view'], ['users.view', 'users.edit']));
        $this->assertFalse(Permissions::isSubset(['users.edit'], ['users.view']));
        $this->assertTrue(Permissions::isSubset([], []));
        $this->assertSame(
            ['added' => ['users.edit'], 'removed' => ['tools.use']],
            Permissions::diff(['users.view', 'tools.use'], ['users.view', 'users.edit'])
        );
        $s = Permissions::summary(['assistants.use', 'users.view', 'users.edit']);
        $this->assertSame(['Assistants'], $s['features']);
        $this->assertSame(['Comptes'], $s['admin']);
        $this->assertSame(3, $s['count']);
        $this->assertSame('Assistants — Modifier', Permissions::label('assistants.edit'));
        $this->assertSame('Bases de connaissances — Gérer', Permissions::label('knowledge_bases.manage'));
        $this->assertSame('inconnu.view', Permissions::label('inconnu.view'));
    }

    public function testLeCatalogueDuNavigateurPorteLesImplications(): void
    {
        $cat = Permissions::clientCatalog();
        $this->assertSame(array_keys(Permissions::FEATURES), array_column($cat['features'], 'key'));
        $this->assertSame(Permissions::allKeys(), $cat['all_keys']);
        // Une table vide reste un OBJET JSON : roles.js y lit `f.requires[action]`.
        $json = (string)json_encode($cat);
        $this->assertStringContainsString('"key":"dashboard","section":"admin"', $json);
        $this->assertStringContainsString('"requires":{}', $json);
        $this->assertStringContainsString('"requires":{"create":["view"]', $json);
    }

    /**
     * Les libellés du catalogue sont des clés CALCULÉES (des constantes) : le
     * test de couverture du dictionnaire ne les voit pas. Chacun a sa
     * traduction, et l'écran les lit par les accesseurs — en anglais, la
     * colonne « Voir » se lit « View » (contexte `droit`), pas « See ».
     */
    public function testLeCatalogueSeTraduitALAccesseur(): void
    {
        $en = (array)(require dirname(__DIR__, 2) . '/lang/en.php');
        $cles = array_merge(array_values(Permissions::ACTION_DEFINITIONS), [Permissions::VIEW_USE_NOTE], array_values(Permissions::NA_REASONS));
        foreach (array_merge(Permissions::SECTIONS, Permissions::ADMIN_ONLY) as $def) {
            $cles[] = $def['label'];
            $cles[] = $def['summary'];
        }
        foreach (Permissions::FEATURES as $def) {
            $cles[] = $def['label'];
            $cles[] = $def['summary'];
            array_push($cles, ...array_values($def['actions']));
        }
        foreach (Permissions::ACTION_LABELS as $libelle) {
            $avecContexte = Permissions::LABEL_CONTEXT . '|' . $libelle;
            $cles[] = isset($en[$avecContexte]) ? $avecContexte : $libelle;
        }
        foreach (array_unique($cles) as $cle) {
            $this->assertArrayHasKey($cle, $en, "Traduction absente : $cle");
        }

        $avant = I18n::locale();
        I18n::use('en');
        try {
            $this->assertSame('View', Permissions::actionLabel(Permissions::VIEW));
            $this->assertSame(['Edit'], Permissions::requiredLabels('assistants', 'manage'));
            $this->assertSame('Knowledge bases — Manage', Permissions::label('knowledge_bases.manage'));
            $this->assertSame('Features', Permissions::sections()[Permissions::SECTION_FEATURES]['label']);
            $cat = Permissions::clientCatalog();
            $this->assertSame('View', $cat['actions'][Permissions::VIEW]);
            $this->assertSame('Accounts', $cat['features'][array_search('users', array_column($cat['features'], 'key'), true)]['label']);
            $this->assertSame($en[Permissions::NA_REASONS['delete']], Permissions::naReason('dashboard', 'delete'));
        } finally {
            I18n::use($avant);
        }
        // La source reste le français : le wiki et les autres tests le lisent.
        $this->assertSame('Voir', Permissions::actionLabel(Permissions::VIEW));
    }

    // ------------------------------------------------------------------
    // Câblage : tout droit nommé dans le code existe au catalogue
    // ------------------------------------------------------------------

    public function testToutDroitNommeDansLeCodeExisteAuCatalogue(): void
    {
        $motifs = [
            // requirePermission('x'), requireAnyPermission('x', 'y'), requireAllPermissions(...)
            '/AuthMiddleware::require(?:Permission|AnyPermission|AllPermissions)\(([^)]*)\)/',
            '/Session::can(?:Any)?\(([^)]*)\)/',
            '/Role::userCan\([^,]+,\s*([^)]*)\)/',
            '/Role::grants\([^,]+,\s*([^)]*)\)/',
        ];
        $trouves = 0;
        $fichiers = [];
        foreach (['src', 'templates'] as $dossier) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::racine() . '/' . $dossier));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                    $fichiers[] = $f->getPathname();
                }
            }
        }
        foreach ($fichiers as $chemin) {
            $code = (string)file_get_contents($chemin);
            foreach ($motifs as $motif) {
                if (!preg_match_all($motif, $code, $m)) {
                    continue;
                }
                foreach ($m[1] as $args) {
                    if (!preg_match_all("/'([a-z_]+\\.[a-z_]+)'/", $args, $cles)) {
                        continue; // droit calculé ('schedules.' . $action) : couvert plus bas
                    }
                    foreach ($cles[1] as $cle) {
                        $trouves++;
                        $this->assertTrue(Permissions::isKnown($cle), basename($chemin) . " nomme un droit inconnu : $cle");
                    }
                }
            }
            // ScheduledTask::allows($u, 'view') / Workflow::allows($u, 'create')
            if (preg_match_all("/(ScheduledTask|Workflow)::allows\\([^,]+,\\s*'([a-z]+)'\\)/", $code, $m, PREG_SET_ORDER)) {
                foreach ($m as [, $classe, $action]) {
                    $trouves++;
                    $feature = $classe === 'ScheduledTask' ? 'schedules' : 'workflows';
                    $this->assertTrue(Permissions::isKnown("$feature.$action"), basename($chemin) . " : $classe::allows(…, '$action')");
                }
            }
        }
        $this->assertGreaterThan(150, $trouves, 'Le balayage doit trouver les gardes du code.');
        // Les pages de l'administration ouvrent sur des droits connus.
        foreach (AdminAccess::PAGES as $page => [$droit, $feature, $ecritures]) {
            if ($droit === null) {
                continue;
            }
            $this->assertTrue(Permissions::isKnown($droit), "$page : $droit");
            foreach ($ecritures as $action) {
                $this->assertTrue(Permissions::isKnown("$feature.$action"), "$page : $feature.$action");
            }
        }
    }
}
