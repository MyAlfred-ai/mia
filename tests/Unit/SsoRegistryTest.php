<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Gardes du registre d'authentification.
 *
 * Deux invariants portent tout le reste :
 *  - le démultiplexage des retours OAuth, qui doit distinguer une CONNEXION
 *    d'un retour de connecteur personnel sans jamais les confondre (ils
 *    partagent la même URI de redirection) ;
 *  - l'impossibilité de fermer l'application depuis l'écran d'administration.
 */
final class SsoRegistryTest extends TestCase
{
    // ---- Démultiplexage des retours OAuth ---------------------------------

    public function testChaqueFournisseurEstReconnuParSonPrefixe(): void
    {
        $this->assertSame(
            SsoRegistry::GOOGLE,
            SsoRegistry::providerFromState('sso_google_' . str_repeat('a', 32))
        );
        $this->assertSame(
            SsoRegistry::MICROSOFT,
            SsoRegistry::providerFromState('sso_ms_' . str_repeat('b', 32))
        );
    }

    public function testLesPrefixesDesConnecteursNeSontJamaisCaptures(): void
    {
        // Ces retours appartiennent aux connecteurs personnels et sont traités
        // AVANT le SSO dans AuthController::callback(). Les capturer ici
        // ouvrirait une session à la place de brancher un connecteur.
        foreach (['drive_', 'gmail_', 'gcal_', 'onedrive_'] as $prefixe) {
            $this->assertNull(
                SsoRegistry::providerFromState($prefixe . str_repeat('c', 32)),
                'Le state « ' . $prefixe . '… » appartient à un connecteur, pas à une connexion.'
            );
        }
    }

    public function testLesStatesNatifsDesConnecteursNeSontPasCaptures(): void
    {
        // Variante portée signée : `drive_native_<id>_<exp>_<hmac>`.
        $this->assertNull(SsoRegistry::providerFromState('drive_native_12_99_abcdef'));
    }

    public function testUnStateSansPrefixeRetombeSurGoogle(): void
    {
        // Au déploiement, une connexion Google ouverte juste avant porte encore
        // l'ancien format nu. La refuser déconnecterait ces utilisateurs sous
        // leurs yeux, sans qu'ils comprennent pourquoi.
        $this->assertSame(SsoRegistry::GOOGLE, SsoRegistry::providerFromState(str_repeat('d', 32)));
    }

    public function testUnStateVideNeDesigneAucunFournisseur(): void
    {
        $this->assertNull(SsoRegistry::providerFromState(''));
    }

    public function testAucunPrefixeNestPrefixeDunAutre(): void
    {
        $prefixes = array_merge(
            array_keys(SsoRegistry::STATE_PREFIXES),
            SsoRegistry::CONNECTOR_PREFIXES
        );
        foreach ($prefixes as $a) {
            foreach ($prefixes as $b) {
                if ($a === $b) {
                    continue;
                }
                $this->assertFalse(
                    str_starts_with($a, $b),
                    'Le préfixe « ' . $a . ' » commence par « ' . $b . " » : le démultiplexage\n"
                        . 'du callback deviendrait dépendant de l\'ordre des tests.'
                );
            }
        }
    }

    // ---- Garde anti-verrouillage ------------------------------------------

    public function testOnNePeutPasEteindreToutesLesMethodes(): void
    {
        $etat = SsoRegistry::guardLockout([
            SsoRegistry::GOOGLE    => false,
            SsoRegistry::MICROSOFT => false,
            SsoRegistry::PASSWORD  => false,
        ]);

        $this->assertTrue($etat[SsoRegistry::PASSWORD], 'Plus personne ne pourrait se connecter.');
        $this->assertTrue($etat['corrige'], 'La correction doit être signalée à l\'administrateur.');
    }

    public function testUnSeulFournisseurSuffitAGarderLaPorteOuverte(): void
    {
        $etat = SsoRegistry::guardLockout([
            SsoRegistry::GOOGLE    => true,
            SsoRegistry::MICROSOFT => false,
            SsoRegistry::PASSWORD  => false,
        ]);

        $this->assertFalse($etat[SsoRegistry::PASSWORD], 'Le mot de passe n\'a pas à être rétabli de force.');
        $this->assertFalse($etat['corrige']);
    }

    public function testUnEtatDejaValideNestPasModifie(): void
    {
        $etat = SsoRegistry::guardLockout([
            SsoRegistry::GOOGLE    => true,
            SsoRegistry::MICROSOFT => true,
            SsoRegistry::PASSWORD  => true,
        ]);

        $this->assertTrue($etat[SsoRegistry::GOOGLE]);
        $this->assertTrue($etat[SsoRegistry::MICROSOFT]);
        $this->assertTrue($etat[SsoRegistry::PASSWORD]);
        $this->assertFalse($etat['corrige']);
    }

    // ---- Domaines autorisés ------------------------------------------------

    public function testNormalisationEtDeduplicationDesDomaines(): void
    {
        $domaines = SsoRegistry::normalizeDomains("Exemple.FR\n@exemple.fr, autre.com;  exemple.fr\n");

        $this->assertSame(['exemple.fr', 'autre.com'], $domaines);
    }

    public function testUneSaisieSansPointNestPasUnDomaine(): void
    {
        // « exemple » seul rendrait la liste inopérante en silence.
        $this->assertSame([], SsoRegistry::normalizeDomains("exemple\nlocalhost"));
    }

    public function testListeVideSignifieAucuneRestriction(): void
    {
        $this->assertTrue(SsoRegistry::domainAllowed('qui-que-ce-soit@ailleurs.net', []));
    }

    public function testSeulesLesAdressesDesDomainesDeclaresPassent(): void
    {
        $domaines = ['exemple.fr'];

        $this->assertTrue(SsoRegistry::domainAllowed('Alice@Exemple.FR', $domaines));
        $this->assertFalse(SsoRegistry::domainAllowed('mallory@autre.fr', $domaines));
    }

    public function testUnSousDomaineNestPasLeDomaine(): void
    {
        // « exemple.fr.attaquant.net » ne doit pas passer pour « exemple.fr ».
        $this->assertFalse(SsoRegistry::domainAllowed('bob@exemple.fr.attaquant.net', ['exemple.fr']));
    }

    public function testUneAdresseSansArobaseEstRefusee(): void
    {
        $this->assertFalse(SsoRegistry::domainAllowed('pas-une-adresse', ['exemple.fr']));
    }

    // ---- Annuaire Microsoft ------------------------------------------------

    public function testUnAnnuaireVideVautCommon(): void
    {
        $this->assertSame('common', SsoRegistry::normalizeTenant(''));
        $this->assertSame('common', SsoRegistry::normalizeTenant('   '));
    }

    public function testLesAliasEtIdentifiantsDAnnuaireSontAcceptes(): void
    {
        $this->assertSame('organizations', SsoRegistry::normalizeTenant('Organizations'));
        $this->assertSame('consumers', SsoRegistry::normalizeTenant('consumers'));
        $this->assertSame(
            '72f988bf-86f1-41af-91ab-2d7cd011db47',
            SsoRegistry::normalizeTenant('72F988BF-86F1-41AF-91AB-2D7CD011DB47')
        );
        $this->assertSame('contoso.onmicrosoft.com', SsoRegistry::normalizeTenant('contoso.onmicrosoft.com'));
    }

    public function testUnAnnuaireFantaisisteRetombeSurCommon(): void
    {
        // Plutôt qu'une URL d'autorisation malformée et une erreur Microsoft
        // incompréhensible, on repart du défaut.
        $this->assertSame('common', SsoRegistry::normalizeTenant('mon annuaire !'));
    }

    // ---- Catalogue ---------------------------------------------------------

    public function testChaqueFournisseurEstCatalogueAvecSonPrefixe(): void
    {
        foreach (SsoRegistry::PROVIDERS as $cle) {
            $entree = SsoRegistry::catalog()[$cle] ?? null;
            $this->assertIsArray($entree, 'Fournisseur absent du catalogue : ' . $cle);
            $this->assertNotSame('', (string)$entree['label']);
            $this->assertSame(
                $cle,
                SsoRegistry::STATE_PREFIXES[$entree['state_prefix']] ?? null,
                'Le préfixe déclaré au catalogue doit être celui que lit providerFromState().'
            );
        }
    }

    public function testLaFabriqueRendUneInstanceParFournisseur(): void
    {
        $this->assertInstanceOf(GoogleSsoProvider::class, SsoRegistry::provider(SsoRegistry::GOOGLE));
        $this->assertInstanceOf(MicrosoftSsoProvider::class, SsoRegistry::provider(SsoRegistry::MICROSOFT));
        $this->assertNull(SsoRegistry::provider('facebook'));
    }

    // ---- Enregistrement section par section --------------------------------

    /**
     * Les sections des interrupteurs portent le NOM de leur méthode : le
     * handler construit `{$section}_enabled` et `setEnabled($section, …)`
     * dessus. Une constante qui divergerait casserait l'enregistrement en
     * silence (case jamais lue, réglage jamais écrit).
     */
    public function testLesSectionsAInterrupteurPortentLeNomDeLeurMethode(): void
    {
        $this->assertSame(SsoRegistry::GOOGLE, SsoRegistry::SECTION_GOOGLE);
        $this->assertSame(SsoRegistry::MICROSOFT, SsoRegistry::SECTION_MICROSOFT);
        $this->assertSame(SsoRegistry::PASSWORD, SsoRegistry::SECTION_PASSWORD);
        foreach ([SsoRegistry::SECTION_DOMAINS, SsoRegistry::SECTION_LOGIN] as $section) {
            $this->assertContains($section, SsoRegistry::SECTIONS);
            $this->assertNotContains($section, [SsoRegistry::GOOGLE, SsoRegistry::MICROSOFT, SsoRegistry::PASSWORD]);
        }
        $this->assertSame(SsoRegistry::SECTIONS, array_values(array_unique(SsoRegistry::SECTIONS)));
    }

    public function testLaFusionDEtatNeToucheQuALaSectionPostee(): void
    {
        $courant = [
            SsoRegistry::GOOGLE    => true,
            SsoRegistry::MICROSOFT => false,
            SsoRegistry::PASSWORD  => true,
        ];

        $etat = SsoRegistry::mergeDesiredState($courant, SsoRegistry::SECTION_GOOGLE, false);
        $this->assertFalse($etat[SsoRegistry::GOOGLE]);
        $this->assertFalse($etat[SsoRegistry::MICROSOFT], 'Microsoft garde son état enregistré.');
        $this->assertTrue($etat[SsoRegistry::PASSWORD], 'Le mot de passe garde son état enregistré.');

        $etat = SsoRegistry::mergeDesiredState($courant, SsoRegistry::SECTION_MICROSOFT, true);
        $this->assertTrue($etat[SsoRegistry::MICROSOFT]);
        $this->assertTrue($etat[SsoRegistry::GOOGLE]);

        // Une section sans interrupteur ne change rien.
        $this->assertSame(
            $courant,
            SsoRegistry::mergeDesiredState($courant, SsoRegistry::SECTION_DOMAINS, false)
        );
    }

    /**
     * Le scénario qui motivait la fusion : enregistrer Google ÉTEINT alors que
     * le mot de passe est actif ne doit rien corriger — et l'éteindre alors
     * qu'il était le dernier moyen doit rétablir le mot de passe.
     */
    public function testLaGardeRaisonneSurLEtatFusionne(): void
    {
        $avecMotDePasse = [SsoRegistry::GOOGLE => true, SsoRegistry::MICROSOFT => false, SsoRegistry::PASSWORD => true];
        $etat = SsoRegistry::guardLockout(SsoRegistry::mergeDesiredState($avecMotDePasse, SsoRegistry::SECTION_GOOGLE, false));
        $this->assertFalse($etat['corrige'], 'Le mot de passe enregistré suffit à garder la porte ouverte.');

        $googleSeul = [SsoRegistry::GOOGLE => true, SsoRegistry::MICROSOFT => false, SsoRegistry::PASSWORD => false];
        $etat = SsoRegistry::guardLockout(SsoRegistry::mergeDesiredState($googleSeul, SsoRegistry::SECTION_GOOGLE, false));
        $this->assertTrue($etat['corrige']);
        $this->assertTrue($etat[SsoRegistry::PASSWORD]);

        $etat = SsoRegistry::guardLockout(SsoRegistry::mergeDesiredState($googleSeul, SsoRegistry::SECTION_PASSWORD, false));
        $this->assertFalse($etat['corrige'], 'Décocher un mot de passe déjà éteint, Google actif : rien à corriger.');
    }

    // ---- Message de la page de connexion -----------------------------------

    public function testLeMessageDeConnexionEstNormalise(): void
    {
        $this->assertSame('', SsoRegistry::normalizeLoginMessage(''));
        $this->assertSame('', SsoRegistry::normalizeLoginMessage("  \n\t "));
        $this->assertSame("Bonjour\nMonde", SsoRegistry::normalizeLoginMessage("Bonjour\r\nMonde"), 'CRLF unifié.');
        $this->assertSame("Bonjour\n\nMonde", SsoRegistry::normalizeLoginMessage("Bonjour\n\n\n\n\nMonde"), 'Lignes vides en série ramenées à une.');
        $this->assertSame("Bonjour\nMonde", SsoRegistry::normalizeLoginMessage("Bonjour   \nMonde"), 'Blancs de fin de ligne retirés.');
        $this->assertSame('Bonjour Monde', SsoRegistry::normalizeLoginMessage("Bon\x07jour \x00Monde"), 'Caractères de contrôle retirés.');
        $this->assertSame('Écrivez à support@exemple.fr.', SsoRegistry::normalizeLoginMessage('  Écrivez à support@exemple.fr.  '));
    }

    /**
     * La borne compte des CARACTÈRES, comme le `maxlength` de l'écran : des
     * octets couperaient un texte accentué bien avant les 500 annoncés.
     */
    public function testLeMessageDeConnexionEstBorneEnCaracteres(): void
    {
        $accentue = str_repeat('é', SsoRegistry::LOGIN_MESSAGE_MAX + 50);
        $borne    = SsoRegistry::normalizeLoginMessage($accentue);
        $this->assertSame(SsoRegistry::LOGIN_MESSAGE_MAX, mb_strlen($borne));
        $this->assertSame(str_repeat('é', SsoRegistry::LOGIN_MESSAGE_MAX), $borne);

        $juste = str_repeat('a', SsoRegistry::LOGIN_MESSAGE_MAX);
        $this->assertSame($juste, SsoRegistry::normalizeLoginMessage($juste), 'Pile à la borne : intact.');
    }
}
