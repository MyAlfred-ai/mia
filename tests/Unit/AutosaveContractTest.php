<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Enregistrement automatique des écrans de configuration.
 *
 * Le contrat tient en trois morceaux qui doivent rester alignés :
 *   1. le module client (`autosave.js`) poste le formulaire ENTIER sur sa
 *      propre action, avec l'en-tête AJAX ;
 *   2. le handler PHP visé sait répondre en JSON `{"ok": true}` — une
 *      redirection 302 serait lue comme un échec, et sans le `ok` explicite le
 *      module fêterait un succès qu'il n'a pas vérifié ;
 *   3. le gabarit n'a plus de bouton « Enregistrer », mais porte
 *      `data-autosave` et la mention qui remplace le bouton disparu.
 *
 * Casser l'un des trois sans les autres donne un écran qui ment : soit il
 * annonce « Enregistré » sans rien avoir écrit, soit il n'enregistre plus rien
 * du tout et n'offre aucun moyen de le faire. D'où ces gardes.
 */
final class AutosaveContractTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = dirname(__DIR__, 2) . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    /** Gabarits dont le formulaire principal s'enregistre tout seul. */
    private const GABARITS_AUTOSAVE = [
        'templates/admin/settings.php',
        // Entreprise : sa page depuis qu'elle a quitté Marque & apparence.
        'templates/admin/company.php',
        'templates/admin/credits.php',
        // Comportement par défaut : carte #defaut de la page Modèles.
        'templates/admin/llms/models.php',
        'templates/admin/llms/keys.php',
        'templates/admin/tools.php',
        // Fiche d'une base : une page, plus une modale — armée en
        // modification seulement (testLaFicheDUneBaseNArmeQueLaModification).
        'templates/admin/knowledge-bases/edit.php',
        'templates/admin/knowledge-bases-sync.php',
        // /admin/sso est VOLONTAIREMENT absent : la page d'authentification
        // s'enregistre section par section, chacune avec son bouton — des
        // identifiants de connexion ne partent jamais à moitié saisis
        // (SsoCablageTest::testLaPageDAuthentificationSEnregistreSectionParSection).
        'templates/admin/users.php',
        'templates/admin/user-groups.php',
        'templates/admin/profiles/edit.php',
        'templates/admin/pruning.php',
        'templates/settings/profile.php',
        'templates/settings/ai.php',
        // Thème et taille d'affichage : appliqués à la page dès le clic.
        'templates/settings/display.php',
        // Préférences du connecteur Google Tasks (liste par défaut, horizon,
        // suppression autorisée, consignes) : un écran de configuration de plus,
        // donc soumis au même contrat.
        'templates/settings/connections.php',
    ];

    // ------------------------------------------------------------------
    // 1. Le socle
    // ------------------------------------------------------------------

    public function testLeModuleEtLeHelperExistent(): void
    {
        $this->assertNotSame('', trim(self::lire('public/assets/js/autosave.js')));
        $this->assertNotSame('', trim(self::lire('src/Http/AjaxResponse.php')));
    }

    /**
     * Sans `require` dans `config/classes.php`, `AjaxResponse` est introuvable
     * — et il n'y a pas d'autoloader. La panne serait un 500 sur le premier
     * enregistrement, en production seulement si la liste divergeait.
     */
    public function testAjaxResponseEstChargeParLeSocleCommun(): void
    {
        $this->assertStringContainsString(
            "/src/Http/AjaxResponse.php",
            self::lire('config/classes.php'),
            'AjaxResponse doit être requis par la liste de classes partagée web + CLI.'
        );
    }

    /**
     * Le module est chargé depuis `admin-nav.php`, seule ossature commune à
     * /admin/* ET /settings/*, et en `defer` : la nav est rendue AVANT les
     * formulaires, un script synchrone ne trouverait rien à brancher.
     */
    public function testLeModuleEstChargeEnDeferDepuisLOssatureCommune(): void
    {
        $nav = self::lire('templates/partials/admin-nav.php');
        $this->assertStringContainsString('/assets/js/autosave.js', $nav);
        $this->assertMatchesRegularExpression(
            '#<script\s+defer\s+src="/assets/js/autosave\.js#',
            $nav,
            'Le module doit être chargé en `defer`.'
        );
    }

    public function testLesStylesDeLaPastilleExistent(): void
    {
        $css = self::lire('public/assets/css/main.css');
        foreach (['.autosave-pill', '.autosave-inline', '.autosave-hint', '.autosave-spinner'] as $classe) {
            $this->assertStringContainsString($classe, $css, "Style « $classe » manquant.");
        }
    }

    // ------------------------------------------------------------------
    // 2. Le contrat client
    // ------------------------------------------------------------------

    /**
     * Le formulaire est posté EN ENTIER. Les handlers PHP lisent l'absence
     * d'une case à cocher comme « décoché » (`$_POST['is_active'] ?? 0`) : un
     * envoi champ par champ éteindrait tout ce qui n'a pas été renvoyé.
     */
    public function testLeModulePosteLeFormulaireEntierAvecLesEnTetesAttendus(): void
    {
        $js = self::lire('public/assets/js/autosave.js');
        $this->assertStringContainsString('form.elements', $js, 'Le corps est construit depuis tout le formulaire.');
        $this->assertStringContainsString("'X-CSRF-Token'", $js);
        $this->assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $js);
        $this->assertStringContainsString("'Accept': 'application/json'", $js);
    }

    /**
     * `data.ok !== true` : un HTTP 200 ne suffit pas. Une route restée en
     * flash + redirect renvoie du HTML avec un 200 après redirection suivie —
     * l'annoncer « Enregistré » masquerait la panne.
     */
    public function testLeModuleExigeUnOkExpliciteDuServeur(): void
    {
        $js = self::lire('public/assets/js/autosave.js');
        $this->assertStringContainsString('data.ok !== true', $js);
    }

    /**
     * Les évènements sont l'API des écrans qui se greffent dessus
     * (sérialisation des crédits, rechargement des modales, aperçus d'avatar).
     */
    public function testLeModuleExposeSonApiEtSesEvenements(): void
    {
        $js = self::lire('public/assets/js/autosave.js');
        foreach (['mia:autosave-before', 'mia:autosaved', 'mia:autosave-failed'] as $evenement) {
            $this->assertStringContainsString($evenement, $js, "Évènement « $evenement » manquant.");
        }
        foreach (['closeAndReload', 'settle', 'paint', 'save:', 'flush'] as $membre) {
            $this->assertStringContainsString($membre, $js, "Membre d'API « $membre » manquant.");
        }
    }

    /**
     * Un champ rattaché par `form="…"` mais placé hors du formulaire (la case
     * « Actif » de la fiche assistant) fait remonter ses évènements dans
     * l'arbre DOM, jamais vers son formulaire : la délégation doit vivre sur
     * `document` et résoudre le formulaire via `field.form`.
     */
    public function testLaDelegationPasseParDocumentEtFieldForm(): void
    {
        $js = self::lire('public/assets/js/autosave.js');
        $this->assertStringContainsString("document.addEventListener('input'", $js);
        $this->assertStringContainsString("document.addEventListener('change'", $js);
        $this->assertStringContainsString('target.form', $js);
    }

    // ------------------------------------------------------------------
    // 3. Le contrat serveur
    // ------------------------------------------------------------------

    /**
     * Toute route visée par un formulaire auto-enregistré doit passer par
     * AjaxResponse. La liste est explicite : y ajouter un écran sans ajouter
     * sa route ici laisserait passer une régression silencieuse.
     */
    public function testLesHandlersDeConfigurationRepondentEnJson(): void
    {
        $admin = self::lire('src/Controllers/AdminController.php');
        $methodes = [
            'saveSettings', 'saveCredits', 'saveApiKey', 'saveLlmDefaults',
            'saveKnowledgeBaseSyncSettings', 'saveUserAccessRules',
            'updateUser', 'updateUserGroup', 'updateTool', 'updateKnowledgeBase', 'updateProfile',
        ];
        foreach ($methodes as $methode) {
            $corps = $this->corpsDeMethode($admin, $methode);
            $this->assertStringContainsString(
                'AjaxResponse::',
                $corps,
                "AdminController::{$methode}() doit répondre en JSON quand la requête est AJAX."
            );
        }

        $settings = self::lire('src/Controllers/UserSettingsController.php');
        foreach (['updateProfile', 'updateAiPreferences', 'saveTasksPrefs'] as $methode) {
            $corps = $this->corpsDeMethode($settings, $methode);
            $this->assertStringContainsString(
                'respondSettings',
                $corps,
                "UserSettingsController::{$methode}() doit passer par le helper à double sortie."
            );
        }
    }

    /**
     * Le mode sans JavaScript ne doit pas disparaître : chaque sortie JSON a
     * son pendant flash + redirection dans le même helper.
     */
    public function testLeHelperGardeLaSortieSansJavascript(): void
    {
        $helper = self::lire('src/Http/AjaxResponse.php');
        $this->assertStringContainsString('Session::flash', $helper);
        $this->assertStringContainsString("header('Location: '", $helper);
        $this->assertStringContainsString('X_REQUESTED_WITH', $helper);
    }

    /**
     * `is_active` : case décochée = clé absente du POST = inactif. Le défaut à
     * 1 rendait les interrupteurs « Actif » des assistants et des bases de
     * connaissances inopérants — invisible tant qu'un bouton « Enregistrer »
     * masquait le geste, flagrant dès que l'écran s'enregistre seul.
     */
    public function testUneCaseActiveDecocheeDesactiveVraiment(): void
    {
        $admin = self::lire('src/Controllers/AdminController.php');
        foreach (['createProfile', 'updateProfile', 'createKnowledgeBase', 'updateKnowledgeBase'] as $methode) {
            $corps = $this->corpsDeMethode($admin, $methode);
            $this->assertStringNotContainsString(
                "\$_POST['is_active'] ?? 1",
                $corps,
                "{$methode}() : une case « Actif » décochée doit valoir 0, pas 1."
            );
        }
    }

    /**
     * Un réglage déménagé sur un autre écran ne doit plus être écrit par
     * l'écran d'origine : le formulaire ne le porte plus, donc une case absente
     * du POST y vaudrait « décochée » et chaque enregistrement des réglages
     * généraux éteindrait l'activation automatique des nouveaux comptes. Le
     * piège a déjà été payé une fois avec les interrupteurs des connecteurs.
     */
    public function testUnReglageDemenageNEstPlusEcritParLEcranDOrigine(): void
    {
        $corps = $this->corpsDeMethode(self::lire('src/Controllers/AdminController.php'), 'saveSettings');
        foreach (['new_users_active', 'staff_email_domain', 'common_vocabulary', 'google_drive_api_key'] as $cle) {
            $this->assertStringNotContainsString(
                "Settings::set('{$cle}'",
                $corps,
                "saveSettings() ne doit plus écrire « {$cle} » : le réglage vit sur un autre écran."
            );
        }
        // Les clés de synchronisation passent par des constantes de KbSync,
        // celles des moyens de connexion par des constantes de SsoRegistry.
        $this->assertStringNotContainsString('KbSync::SETTING_', $corps);
        // L'entreprise a sa page (/admin/company, saveCompany).
        $this->assertStringNotContainsString(
            'CompanyProfile::',
            $corps,
            'Le nom, le descriptif, le logo et le modèle PowerPoint de l\'entreprise vivent sur /admin/company.'
        );
        $this->assertStringNotContainsString(
            'SsoRegistry::SETTING_',
            $corps,
            'Les identifiants d\'authentification vivent sur /admin/sso : les écrire ici les '
                . 'effacerait à chaque enregistrement de « Marque & apparence ».'
        );
    }

    /**
     * `{id}` capture n'importe quel segment : une route littérale déclarée
     * APRÈS lui n'est jamais atteinte. « access-rules » arriverait dans
     * updateUser(), avec un identifiant non numérique.
     */
    public function testLesRoutesLitteralesPrecedentLeurRouteParametree(): void
    {
        $routes = self::lire('public/index.php');
        $cas = [
            "post('/admin/users/access-rules'" => "post('/admin/users/{id}'",
        ];
        foreach ($cas as $litterale => $parametree) {
            $a = strpos($routes, $litterale);
            $b = strpos($routes, $parametree);
            $this->assertNotFalse($a, "Route absente : $litterale");
            $this->assertNotFalse($b, "Route absente : $parametree");
            $this->assertLessThan($b, $a, "$litterale doit être déclarée AVANT $parametree.");
        }
    }

    /**
     * Le formulaire des clés ne réaffiche jamais le secret enregistré. Sans
     * reprise de l'existant, modifier le modèle d'un fournisseur — ou l'URL du
     * serveur local — écraserait la clé par le chiffré d'une chaîne vide, et à
     * la frappe ce serait systématique.
     */
    public function testUneCleApiVideEstConservee(): void
    {
        $corps = $this->corpsDeMethode(self::lire('src/Controllers/AdminController.php'), 'saveApiKey');
        $this->assertStringContainsString('ApiKey::findByProvider', $corps);
        $this->assertStringContainsString('Encryption::decrypt', $corps);
    }

    // ------------------------------------------------------------------
    // 4. Le contrat des gabarits
    // ------------------------------------------------------------------

    public function testLesEcransDeConfigurationPortentDataAutosave(): void
    {
        foreach (self::GABARITS_AUTOSAVE as $gabarit) {
            $this->assertStringContainsString(
                'data-autosave',
                self::lire($gabarit),
                "$gabarit n'arme aucun formulaire."
            );
        }
    }

    /**
     * Plus aucun bouton « Enregistrer » / « Sauvegarder ». Quatre exceptions
     * assumées : le `<noscript>` des connecteurs (seul recours sans
     * JavaScript), les boutons de CRÉATION, qui restent des actes
     * volontaires (« Créer », « Ajouter »), le bouton `type="button"` du
     * mode relecture de la fiche assistant — un prompt posé par script
     * (modèle, IA) suspend l'auto-save jusqu'à relecture (PromptStartersTest) —
     * et la page Authentification tout entière, hors de GABARITS_AUTOSAVE :
     * un bouton par section, gardé par SsoCablageTest.
     */
    public function testAucunBoutonEnregistrerNeSubsiste(): void
    {
        $gabarits = array_merge(self::GABARITS_AUTOSAVE, ['templates/admin/connectors.php']);
        foreach ($gabarits as $gabarit) {
            $contenu = self::lire($gabarit);
            // Le bloc <noscript> est le mode dégradé : on le retire avant de juger.
            $sansNoscript = (string)preg_replace('#<noscript>.*?</noscript>#s', '', $contenu);

            preg_match_all(
                '#<button[^>]*type="submit"[^>]*>(.*?)</button>#s',
                $sansNoscript,
                $trouves
            );
            foreach ($trouves[1] as $libelle) {
                $texte = trim(strip_tags($libelle));
                $this->assertDoesNotMatchRegularExpression(
                    '/^(Enregistrer|Sauvegarder)/i',
                    $texte,
                    "$gabarit porte encore un bouton « $texte »."
                );
            }
        }
    }

    /**
     * Un écran sans bouton ET sans mention laisse croire qu'il manque une
     * action : la mention remplace le bouton disparu.
     */
    public function testChaqueEcranAffichesLaMentionDEnregistrementAutomatique(): void
    {
        $gabarits = array_merge(self::GABARITS_AUTOSAVE, ['templates/admin/connectors.php']);
        foreach ($gabarits as $gabarit) {
            $this->assertStringContainsString(
                'autosave-hint.php',
                self::lire($gabarit),
                "$gabarit n'affiche pas la mention « enregistrement automatique »."
            );
        }
    }

    /**
     * Les formulaires de modale sont remplis par script : armés trop tôt, ce
     * remplissage passerait pour une saisie et enregistrerait l'objet ouvert
     * — voire, sur un formulaire à double mode, en créerait un.
     */
    public function testLesModalesSontDesarmeesAuRepos(): void
    {
        // Les bases de connaissances ont quitté la liste : leur fiche est une
        // page, gardée par testLaFicheDUneBaseNArmeQueLaModification.
        $modales = [
            'templates/admin/tools.php',
            'templates/admin/users.php',
            'templates/admin/user-groups.php',
        ];
        foreach ($modales as $gabarit) {
            $contenu = self::lire($gabarit);
            $this->assertStringContainsString(
                'data-autosave="off"',
                $contenu,
                "$gabarit doit garder sa modale désarmée tant qu'elle n'est pas remplie."
            );
            $this->assertStringContainsString(
                'data-autosave-reload="close"',
                $contenu,
                "$gabarit : la liste derrière la modale doit être re-rendue à la fermeture."
            );
            $this->assertStringContainsString(
                'closeAndReload',
                $contenu,
                "$gabarit : la fermeture doit passer par closeAndReload (envoi en attente + rechargement)."
            );
        }
    }

    /**
     * La fiche d'une base de connaissances est une PAGE : en création, son
     * formulaire n'est pas armé (on ne crée pas une base à la frappe, le
     * bouton « Créer la base » soumet normalement) ; en modification, il
     * l'est ; en lecture seule (base système), il ne l'est pas non plus.
     * La liste, elle, ne porte plus aucun formulaire de base.
     */
    public function testLaFicheDUneBaseNArmeQueLaModification(): void
    {
        $fiche = self::lire('templates/admin/knowledge-bases/edit.php');
        $this->assertMatchesRegularExpression(
            '/id="editKbForm"[^>]*<\?=\s*\$isCreate\s*\|\|\s*\$readOnly\s*\?\s*\'\'\s*:\s*\'data-autosave\'\s*\?>/',
            $fiche
        );
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*id="kbSaveBtn"[^>]*>(?:<\?= te\(\')?Créer la base/', $fiche);

        $liste = self::lire('templates/admin/knowledge-bases.php');
        $this->assertStringNotContainsString('id="editKbForm"', $liste);
        $this->assertStringNotContainsString('data-autosave', $liste);
        $this->assertStringNotContainsString('kb-modal', $liste);
    }

    /**
     * Une valeur posée par script n'émet aucun évènement : sans `dispatchEvent`
     * explicite, glisser le curseur de température, cliquer une pastille de
     * couleur ou demander la suppression du logo n'enregistrerait rien.
     */
    public function testLesAffectationsParScriptEmettentUnEvenement(): void
    {
        $cas = [
            'templates/admin/profiles/edit.php' => 'mpTempRange',
            'templates/admin/settings.php'      => 'removeLogoHover',
            'templates/admin/company.php'       => 'removeCompanyLogo',
        ];
        foreach ($cas as $gabarit => $ancre) {
            $contenu = self::lire($gabarit);
            $this->assertStringContainsString($ancre, $contenu);
            $this->assertStringContainsString(
                'dispatchEvent(new Event(',
                $contenu,
                "$gabarit : une affectation par script doit émettre son évènement."
            );
        }
    }

    /**
     * Champs à usage unique. Un formulaire qui se reposte en entier à chaque
     * modification rejouerait sinon indéfiniment ce qui ne vaut qu'une fois :
     * un secret déjà enregistré resterait dans la page et repartirait à chaque
     * frappe, et une case « Supprimer la clé » redemanderait la suppression.
     * Les handlers conservent la valeur existante quand le champ est vide,
     * donc vider après coup est sans effet de bord.
     */
    public function testLesChampsAUsageUniqueSontRemisAZero(): void
    {
        $cas = [
            'templates/admin/settings.php' => ['remove_logo_hover'],
            'templates/admin/company.php'  => ['remove_company_logo', 'remove_company_template', 'remove_company_word_template'],
            'templates/admin/knowledge-bases-sync.php' => [
                'google_drive_api_key', 'remove_google_drive_api_key',
                'sharepoint_client_secret', 'remove_sharepoint_credentials',
                'google_calendar_api_key', 'remove_google_calendar_api_key',
            ],
            'templates/admin/llms/keys.php' => ['key'],
        ];
        foreach ($cas as $gabarit => $champs) {
            $contenu = self::lire($gabarit);
            foreach ($champs as $champ) {
                $this->assertMatchesRegularExpression(
                    '#name="' . preg_quote($champ, '#') . '"[^>]*data-autosave-reset#',
                    $contenu,
                    "$gabarit : le champ « $champ » doit être remis à zéro après enregistrement."
                );
            }
        }

        // Modale des outils : les quatre champs `api_key` et la valeur de
        // l'en-tête n8n sont des secrets, jamais réaffichés par le serveur.
        $tools = self::lire('templates/admin/tools.php');
        $this->assertSame(
            5,
            substr_count($tools, 'data-autosave-reset'),
            'Les 5 secrets de la modale d’édition des outils doivent être à usage unique.'
        );
    }

    /**
     * Le module doit savoir décocher une case, pas seulement vider une valeur :
     * `checkbox.value = ''` laisse la case cochée, donc la suppression de clé
     * repartirait à chaque enregistrement suivant.
     */
    public function testLaRemiseAZeroDecocheLesCases(): void
    {
        $js = self::lire('public/assets/js/autosave.js');
        $this->assertMatchesRegularExpression(
            '#data-autosave-reset.*?checked = false#s',
            $js,
            'Une case marquée à usage unique doit être décochée, pas seulement vidée.'
        );
    }

    /**
     * Le sélecteur de fournisseur des clés API désigne la CIBLE de la fiche,
     * pas une valeur : en changer ne doit rien enregistrer, sinon la clé
     * affichée partirait chez le fournisseur suivant.
     */
    public function testLeSelecteurDeFournisseurEstSilencieux(): void
    {
        $this->assertStringContainsString(
            'data-autosave-silent',
            self::lire('templates/admin/llms/keys.php')
        );
    }

    /**
     * Un mot de passe ne se définit pas à la frappe, à moitié saisi : ces
     * formulaires gardent leur bouton et restent hors du module.
     */
    public function testLesFormulairesDeMotDePasseRestentManuels(): void
    {
        $profil = self::lire('templates/settings/profile.php');
        $this->assertMatchesRegularExpression(
            '#<form[^>]+action="/settings/password"(?![^>]*data-autosave)#',
            $profil,
            'Le changement de mot de passe ne doit pas être auto-enregistré.'
        );

        $users = self::lire('templates/admin/users.php');
        $this->assertMatchesRegularExpression(
            '#<form id="pwForm"(?![^>]*data-autosave)#',
            $users,
            'La réinitialisation de mot de passe ne doit pas être auto-enregistrée.'
        );
    }

    /** Extrait le corps d'une méthode PHP, accolades équilibrées. */
    private function corpsDeMethode(string $source, string $methode): string
    {
        $pos = strpos($source, "function {$methode}(");
        $this->assertNotFalse($pos, "Méthode {$methode}() introuvable.");

        $debut = strpos($source, '{', $pos);
        $this->assertNotFalse($debut);

        $niveau = 0;
        $len = strlen($source);
        for ($i = $debut; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $niveau++;
            } elseif ($source[$i] === '}') {
                $niveau--;
                if ($niveau === 0) {
                    return substr($source, $debut, $i - $debut + 1);
                }
            }
        }
        $this->fail("Corps de {$methode}() non délimité.");
    }
}
