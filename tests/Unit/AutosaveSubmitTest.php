<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Soumission d'un formulaire rattaché au module d'enregistrement automatique.
 *
 * Panne corrigée : les modales à double mode (bases de connaissances, groupes)
 * gardent `data-autosave="off"` en CRÉATION — on ne crée pas un objet à la
 * frappe, la création garde son bouton « Créer ». Mais `scan()` rattache tout
 * `form[data-autosave]`, « off » compris, et l'écouteur `submit` du module
 * interceptait l'envoi (`preventDefault`) pour le confier à un `send()` qui
 * refuse de partir quand le module est suspendu : le bouton « Créer » ne
 * faisait plus RIEN, sans une erreur. Et `attach()` pose `novalidate` : la
 * validation native du nom obligatoire avait disparu avec.
 *
 * Le vrai `autosave.js` est exécuté sous node, dans un DOM minimal : une
 * lecture du code ne dirait pas quel écouteur gagne, ni dans quel ordre.
 * Sans node (conteneur applicatif), ces tests sont SKIPPÉS ; ils tournent sur
 * le poste de développement et en CI.
 */
final class AutosaveSubmitTest extends TestCase
{
    /** @var array<string, array{empeche: bool, envoye: bool, validation: bool, rattache: bool}>|null */
    private static ?array $resultats = null;

    public function testUnFormulaireSuspenduSeSoumetNormalement(): void
    {
        $r = self::scenario('suspendu_valide');
        $this->assertTrue($r['rattache'], 'Le module rattache aussi les formulaires suspendus (précondition de la panne).');
        $this->assertFalse($r['empeche'], 'Le bouton « Créer » d’une modale en création doit soumettre le formulaire.');
        $this->assertFalse($r['envoye'], 'Suspendu, le module ne doit rien envoyer lui-même.');
    }

    public function testUnFormulaireSuspenduRetrouveLaValidationNative(): void
    {
        $r = self::scenario('suspendu_invalide');
        $this->assertTrue($r['validation'], '`novalidate` posé par attach() : la validation doit être rejouée à la soumission.');
        $this->assertTrue($r['empeche'], 'Un champ obligatoire vide ne doit pas partir au serveur (modale refermée, saisie perdue).');
        $this->assertFalse($r['envoye']);
    }

    public function testUnBoutonFormnovalidateGardeSonSens(): void
    {
        $r = self::scenario('suspendu_formnovalidate');
        $this->assertFalse($r['validation']);
        $this->assertFalse($r['empeche']);
    }

    public function testUnFormulaireArmeEnregistreSansRechargerLaPage(): void
    {
        foreach (['arme', 'arme_sans_valeur'] as $nom) {
            $r = self::scenario($nom);
            $this->assertTrue($r['empeche'], "$nom : Entrée dans un champ enregistre en AJAX, sans navigation.");
            $this->assertTrue($r['envoye'], "$nom : l'enregistrement doit partir.");
        }
    }

    /** Même formulaire, création puis édition : exactement le parcours de la modale. */
    public function testLeDoubleModeSuitLEtatCourantDuFormulaire(): void
    {
        $creation = self::scenario('double_mode_creation');
        $this->assertFalse($creation['empeche']);
        $this->assertFalse($creation['envoye']);

        $edition = self::scenario('double_mode_edition');
        $this->assertTrue($edition['empeche']);
        $this->assertTrue($edition['envoye']);
    }

    public function testUnRefusDUnAutreEcouteurEstRespecte(): void
    {
        foreach (['refuse_arme', 'refuse_suspendu'] as $nom) {
            $r = self::scenario($nom);
            $this->assertFalse($r['envoye'], "$nom : une validation maison a refusé l'envoi.");
            $this->assertFalse($r['validation'], "$nom : rien à rejouer, l'envoi est déjà refusé.");
        }
    }

    public function testUnFormulaireHorsModuleNEstPasTouche(): void
    {
        $r = self::scenario('hors_module');
        $this->assertFalse($r['rattache']);
        $this->assertFalse($r['empeche']);
        $this->assertFalse($r['envoye']);
        $this->assertFalse($r['validation']);
    }

    /**
     * Le harnais reproduit les formulaires réels : un vrai bouton submit, et
     * un module suspendu (groupes) ou absent (bases) en création. Si l'un
     * d'eux change de forme, ces tests ne prouveraient plus rien — le dire
     * ici plutôt que de rester au vert.
     */
    public function testLesModalesADoubleModeCreentParUnVraiSubmit(): void
    {
        // Bases de connaissances : la fiche est devenue une page. En création,
        // le formulaire ne porte PAS data-autosave — le module ne le rattache
        // pas du tout (scénario « hors_module »), la soumission reste native.
        $kb = (string)file_get_contents(ROOT . '/templates/admin/knowledge-bases/edit.php');
        $bouton = self::balise($kb, 'kbSaveBtn');
        $this->assertStringContainsString('type="submit"', $bouton);
        $this->assertStringContainsString('form="editKbForm"', $bouton);
        $this->assertMatchesRegularExpression(
            '/id="editKbForm"[^>]*<\?=\s*\$isCreate\s*\|\|\s*\$readOnly\s*\?\s*\'\'\s*:\s*\'data-autosave\'\s*\?>/',
            $kb,
            'La fiche des bases ne doit pas être armée en création.'
        );

        $groupes = (string)file_get_contents(ROOT . '/templates/admin/user-groups.php');
        $debut = strpos($groupes, 'id="ugForm"');
        $this->assertNotFalse($debut);
        $formulaire = substr($groupes, $debut, (int)strpos($groupes, '</form>', $debut) - $debut);
        $this->assertStringContainsString('type="submit"', self::balise($formulaire, 'ugSubmitBtn'));
        // Suspendue en création — et en lecture seule, pour un rôle qui voit
        // les groupes sans pouvoir les modifier (Personnes → Rôles).
        $this->assertMatchesRegularExpression(
            "/form\.dataset\.autosave\s*=\s*\(isEdit\s*&&\s*UG_CAN\.edit\)\s*\?\s*'on'\s*:\s*'off'/",
            $groupes,
            'La modale des groupes doit rester suspendue en création.'
        );
    }

    // ------------------------------------------------------------------

    /** Balise ouvrante de l'élément portant cet id. */
    private static function balise(string $html, string $id): string
    {
        $motif = '/<[a-z]+\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/';
        if (preg_match($motif, $html, $m) !== 1) {
            self::fail("Élément #$id introuvable.");
        }
        return $m[0];
    }

    /** @return array{empeche: bool, envoye: bool, validation: bool, rattache: bool} */
    private static function scenario(string $nom): array
    {
        $resultats = self::executer();
        if (!isset($resultats[$nom])) {
            self::fail("Scénario « $nom » absent de la sortie du harnais.");
        }
        return $resultats[$nom];
    }

    /** @return array<string, array{empeche: bool, envoye: bool, validation: bool, rattache: bool}> */
    private static function executer(): array
    {
        if (self::$resultats !== null) {
            return self::$resultats;
        }
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }

        $module = (string)file_get_contents(ROOT . '/public/assets/js/autosave.js');
        $script = self::PREAMBULE . "\n" . $module . "\n" . self::SCENARIOS;

        $fichier = tempnam(sys_get_temp_dir(), 'mia-autosave-js-');
        file_put_contents($fichier, $script);
        try {
            $processus = proc_open([$node, $fichier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
            if (!is_resource($processus)) {
                self::fail('Impossible de lancer node.');
            }
            $sortie  = stream_get_contents($tubes[1]);
            $erreurs = stream_get_contents($tubes[2]);
            fclose($tubes[1]);
            fclose($tubes[2]);
            $code = proc_close($processus);
        } finally {
            @unlink($fichier);
        }

        $resultats = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultats)) {
            self::fail("autosave.js a échoué sous node (code $code) :\n" . $erreurs);
        }
        return self::$resultats = $resultats;
    }

    /** node explicite (NODE_BINARY), sinon le premier du PATH. */
    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }
        return null;
    }

    /**
     * DOM minimal attendu par le module. Les formulaires sont créés AVANT son
     * chargement : c'est `scan()`, au chargement, qui les rattache — comme sur
     * la page réelle.
     */
    private const PREAMBULE = <<<'JS'
        'use strict';
        const ecouteurs = {};
        const envois = [];
        globalThis.t = (s) => s;
        globalThis.CustomEvent = class {
            constructor(type, init) { this.type = type; this.detail = (init || {}).detail; this.defaultPrevented = false; }
            preventDefault() { this.defaultPrevented = true; }
        };
        globalThis.FormData = class { constructor() { this.entrees = []; } append(k, v) { this.entrees.push([k, v]); } };
        // Seul le DÉPART de l'enregistrement compte ici : réponse jamais rendue.
        globalThis.fetch = (url) => { envois.push(url); return new Promise(() => {}); };

        function element() {
            return {
                hidden: false, className: '', innerHTML: '', textContent: '', offsetWidth: 0,
                classList: { add() {}, remove() {}, contains() { return false; } },
                setAttribute() {}, appendChild() {},
                querySelector() { return { textContent: '' }; },
            };
        }

        const formulaires = {};
        function formulaire(nom, etat, valide) {
            const attributs = {};
            const dataset = {};
            if (etat !== null) { attributs['data-autosave'] = etat; dataset.autosave = etat; }
            const champ = {
                name: 'name', type: 'text', value: valide ? 'Base' : '', disabled: false,
                closest() { return null; }, checkValidity() { return valide; },
            };
            return formulaires[nom] = {
                dataset, elements: [champ], validation: false,
                getAttribute(n) { return n === 'action' ? '/admin/knowledge-bases' : (n in attributs ? attributs[n] : null); },
                setAttribute(n, v) { attributs[n] = String(v); },
                hasAttribute(n) { return n in attributs; },
                querySelectorAll() { return []; },
                dispatchEvent() { return true; },
                addEventListener() {}, removeEventListener() {},
                reportValidity() { this.validation = true; return valide; },
            };
        }

        formulaire('suspendu_valide', 'off', true);
        formulaire('suspendu_invalide', 'off', false);
        formulaire('suspendu_formnovalidate', 'off', false);
        formulaire('arme', 'on', true);
        formulaire('arme_sans_valeur', '', true);
        formulaire('double_mode', 'off', true);
        formulaire('refuse_arme', 'on', true);
        formulaire('refuse_suspendu', 'off', true);
        formulaire('hors_module', null, true);

        globalThis.window = { addEventListener() {} };
        globalThis.document = {
            readyState: 'complete',
            body: { appendChild() {} },
            addEventListener(type, fn) { (ecouteurs[type] = ecouteurs[type] || []).push(fn); },
            querySelector() { return null; },
            querySelectorAll(selecteur) {
                return selecteur === 'form[data-autosave]'
                    ? Object.values(formulaires).filter((f) => f.hasAttribute('data-autosave'))
                    : [];
            },
            createElement() { return element(); },
        };
        JS;

    /** Soumissions rejouées comme le navigateur les distribue (évènement `submit` sur `document`). */
    private const SCENARIOS = <<<'JS'
        function soumettre(form, options) {
            options = options || {};
            form.validation = false;
            const avant = envois.length;
            const e = {
                target: form,
                submitter: options.submitter || null,
                defaultPrevented: !!options.dejaRefuse,
                preventDefault() { this.defaultPrevented = true; },
            };
            (ecouteurs.submit || []).forEach((fn) => fn(e));
            return {
                empeche: e.defaultPrevented,
                envoye: envois.length > avant,
                validation: form.validation,
                // attach() pose `novalidate` : c'est la trace du rattachement.
                rattache: form.hasAttribute('novalidate'),
            };
        }

        const f = formulaires;
        const resultats = {
            suspendu_valide:         soumettre(f.suspendu_valide),
            suspendu_invalide:       soumettre(f.suspendu_invalide),
            suspendu_formnovalidate: soumettre(f.suspendu_formnovalidate, { submitter: { formNoValidate: true } }),
            arme:                    soumettre(f.arme),
            arme_sans_valeur:        soumettre(f.arme_sans_valeur),
            double_mode_creation:    soumettre(f.double_mode),
            refuse_arme:             soumettre(f.refuse_arme, { dejaRefuse: true }),
            refuse_suspendu:         soumettre(f.refuse_suspendu, { dejaRefuse: true }),
            hors_module:             soumettre(f.hors_module),
        };
        // Ouverture en édition : la page arme le module en tout dernier.
        f.double_mode.dataset.autosave = 'on';
        resultats.double_mode_edition = soumettre(f.double_mode);

        process.stdout.write(JSON.stringify(resultats));
        JS;
}
