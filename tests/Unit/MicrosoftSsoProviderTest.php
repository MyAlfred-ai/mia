<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gardes de la connexion Microsoft (Entra ID).
 *
 * La signature de l'`id_token` n'est volontairement pas vérifiée : il est
 * récupéré par nous, en back-channel TLS, sur le endpoint token de Microsoft,
 * contre notre secret client (cf. en-tête de MicrosoftSsoProvider). Ce sont donc
 * les CONTRÔLES DE CLAIMS qui tiennent la sécurité du flux — et c'est ce que
 * ce fichier protège.
 *
 * Le test le plus important est celui de `email_verified` : un annuaire ouvert
 * (`common`) laisse n'importe qui créer un tenant et y déclarer l'adresse de
 * son choix. Traiter cette adresse comme attestée offrirait le compte de la
 * victime au premier venu.
 */
final class MicrosoftSsoProviderTest extends TestCase
{
    private const CLIENT = '11111111-2222-3333-4444-555555555555';
    private const TENANT = '72f988bf-86f1-41af-91ab-2d7cd011db47';
    private const NONCE  = 'nonce-de-session';

    /** @param array<string, mixed> $surcharges */
    private static function claims(array $surcharges = []): array
    {
        return $surcharges + [
            'aud'   => self::CLIENT,
            'iss'   => 'https://login.microsoftonline.com/' . self::TENANT . '/v2.0',
            'exp'   => 2_000_000_000,
            'tid'   => self::TENANT,
            'oid'   => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'nonce' => self::NONCE,
            'email' => 'alice@contoso.com',
            'name'  => 'Alice Martin',
        ];
    }

    private static function mapper(array $claims, string $tenant = self::TENANT, string $nonce = self::NONCE): ?array
    {
        return MicrosoftSsoProvider::mapClaims($claims, self::CLIENT, $tenant, $nonce, 1_900_000_000);
    }

    // ---- Décodage du jeton -------------------------------------------------

    public function testLaChargeUtileDuJetonEstLue(): void
    {
        $payload = ['oid' => 'abc', 'tid' => 'def'];
        $jwt     = 'entete.' . rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=') . '.signature';

        $this->assertSame($payload, MicrosoftSsoProvider::claimsFromIdToken($jwt));
    }

    public function testUnJetonMalFormeEstRefuse(): void
    {
        $this->assertNull(MicrosoftSsoProvider::claimsFromIdToken(''));
        $this->assertNull(MicrosoftSsoProvider::claimsFromIdToken('deux.parties'));
        $this->assertNull(MicrosoftSsoProvider::claimsFromIdToken('entete..signature'));
        $this->assertNull(MicrosoftSsoProvider::claimsFromIdToken('entete.cette-charge-nest-pas-du-json.sig'));
    }

    // ---- Contrôles qui remplacent la signature -----------------------------

    public function testUneIdentiteValideEstNormalisee(): void
    {
        $identite = self::mapper(self::claims());

        $this->assertNotNull($identite);
        $this->assertSame(SsoRegistry::MICROSOFT, $identite['provider']);
        $this->assertSame('alice@contoso.com', $identite['email']);
        $this->assertSame('Alice Martin', $identite['name']);
        $this->assertSame('', $identite['picture'], 'Microsoft ne fournit pas d\'URL de photo publique.');
    }

    public function testLeSujetCombineAnnuaireEtObjet(): void
    {
        // `oid` seul n'est unique QUE dans son annuaire : un invité présent
        // dans deux annuaires en porte deux. Sans le `tid`, deux personnes
        // distinctes pourraient entrer en collision sur un même sujet.
        $identite = self::mapper(self::claims());

        $this->assertSame(
            self::TENANT . '.aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            $identite['subject']
        );
    }

    public function testUneAudienceEtrangereEstRefusee(): void
    {
        $this->assertNull(self::mapper(self::claims(['aud' => 'une-autre-application'])));
    }

    public function testUnEmetteurNonMicrosoftEstRefuse(): void
    {
        $this->assertNull(self::mapper(self::claims(['iss' => 'https://attaquant.example/v2.0'])));
        $this->assertNull(self::mapper(self::claims(['iss' => 'https://login.microsoftonline.com/' . self::TENANT])));
    }

    public function testUnEmetteurQuiDesigneUnAutreAnnuaireQueLeJetonEstRefuse(): void
    {
        // `tid` sert de clé d'identité : un jeu de claims où `iss` et `tid`
        // divergent ne doit pas passer sous prétexte que l'émetteur ressemble
        // à une adresse Microsoft.
        $this->assertNull(self::mapper(self::claims([
            'iss' => 'https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/v2.0',
        ])));
    }

    public function testUnJetonExpireEstRefuse(): void
    {
        $this->assertNull(self::mapper(self::claims(['exp' => 1_800_000_000])));
        $this->assertNull(self::mapper(self::claims(['exp' => 0])));
    }

    public function testUnNonceDifferentEstRefuse(): void
    {
        // Sans ce contrôle, un code d'autorisation obtenu ailleurs pourrait
        // être injecté dans la session de la victime.
        $this->assertNull(self::mapper(self::claims(['nonce' => 'autre-nonce'])));
        $this->assertNull(self::mapper(self::claims(['nonce' => null])));
    }

    public function testUnAnnuaireDifferentDeCeluiConfigureEstRefuse(): void
    {
        $claims = self::claims(['tid' => '00000000-0000-0000-0000-000000000000']);

        $this->assertNull(
            self::mapper($claims),
            'Un annuaire précis est configuré : lui seul doit pouvoir émettre des jetons acceptés.'
        );
    }

    public function testUnJetonSansObjetEstRefuse(): void
    {
        $claims = self::claims();
        unset($claims['oid']);

        $this->assertNull(self::mapper($claims));
    }

    // ---- Attestation de l'adresse (le cœur du sujet) -----------------------

    public function testSurUnAnnuaireOuvertLAdresseNestPasAttestee(): void
    {
        $claims = self::claims(['iss' => 'https://login.microsoftonline.com/tenant-tiers/v2.0', 'tid' => 'tenant-tiers']);
        $identite = self::mapper($claims, 'common');

        $this->assertNotNull($identite);
        $this->assertFalse(
            $identite['email_verified'],
            "Sur `common`, n'importe qui peut déclarer l'adresse de son choix dans son propre annuaire."
        );
    }

    public function testLeClaimXmsEdovAtteste(): void
    {
        $claims = self::claims([
            'iss'      => 'https://login.microsoftonline.com/tenant-tiers/v2.0',
            'tid'      => 'tenant-tiers',
            'xms_edov' => 1,
        ]);

        $this->assertTrue(self::mapper($claims, 'common')['email_verified']);
    }

    public function testUnAnnuairePrecisAtteste(): void
    {
        // Les domaines d'un annuaire sont vérifiés par Microsoft à
        // l'inscription : restreindre à cet annuaire suffit.
        $this->assertTrue(self::mapper(self::claims())['email_verified']);
    }

    public function testUneAdresseAbsenteNestJamaisAttestee(): void
    {
        $claims = self::claims();
        unset($claims['email']);

        $identite = self::mapper($claims);
        $this->assertSame('', $identite['email']);
        $this->assertFalse($identite['email_verified']);
    }

    public function testLeNomDUtilisateurPrincipalSertDeReplilAdresse(): void
    {
        $claims = self::claims(['preferred_username' => 'Bob@contoso.com']);
        unset($claims['email']);

        $this->assertSame('bob@contoso.com', self::mapper($claims)['email']);
    }

    public function testUnNomDUtilisateurPrincipalQuiNestPasUneAdresseEstIgnore(): void
    {
        // Sur certains annuaires l'UPN n'est pas une adresse : la recopier
        // produirait un compte dont l'e-mail n'en est pas un.
        $claims = self::claims(['preferred_username' => 'CONTOSO\\bob']);
        unset($claims['email']);

        $this->assertSame('', self::mapper($claims)['email']);
    }

    // ---- Verdict du bouton « Tester » --------------------------------------

    public function testUnSecretInvalideEstUnEchecNomme(): void
    {
        $verdict = MicrosoftSsoProvider::credentialVerdict(401, '{"error":"invalid_client","error_description":"AADSTS7000215: Invalid client secret"}');

        $this->assertFalse($verdict['ok']);
        $this->assertStringContainsString('Secret client invalide', $verdict['error']);
    }

    public function testUnAnnuaireInconnuEstUnEchecNomme(): void
    {
        $verdict = MicrosoftSsoProvider::credentialVerdict(400, '{"error_description":"AADSTS90002: Tenant not found"}');

        $this->assertFalse($verdict['ok']);
        $this->assertStringContainsString('annuaire', $verdict['error']);
    }

    public function testUnRefusPortantSurLaDemandeVautSucces(): void
    {
        // Une application de CONNEXION n'a aucune permission applicative, et le
        // code de test est volontairement faux : Microsoft refuse la demande,
        // mais il a déjà accepté le client — c'est l'information cherchée.
        $verdict = MicrosoftSsoProvider::credentialVerdict(400, '{"error":"invalid_grant","error_description":"AADSTS9002313: Invalid request"}');

        $this->assertTrue($verdict['ok']);
    }

    public function testUnJetonObtenuVautSucces(): void
    {
        $this->assertTrue(MicrosoftSsoProvider::credentialVerdict(200, '{"access_token":"…"}')['ok']);
    }

    public function testLeCorpsBrutNestJamaisRecopieDansLeMessage(): void
    {
        // Le corps d'une erreur de token peut contenir des identifiants.
        $corps   = '{"error":"invalid_client","error_description":"AADSTS7000215 secret=TRES-SECRET"}';
        $verdict = MicrosoftSsoProvider::credentialVerdict(401, $corps);

        $this->assertStringNotContainsString('TRES-SECRET', $verdict['error']);
    }

    public function testSeulLeCodeAadstsEstJournalise(): void
    {
        $this->assertSame('AADSTS7000215', MicrosoftSsoProvider::aadstsCode('… AADSTS7000215: secret=TRES-SECRET'));
        $this->assertSame('code inconnu', MicrosoftSsoProvider::aadstsCode('panne réseau'));
    }

    // ---- Points d'accès ----------------------------------------------------

    public function testLesPointsDAccesPortentLAnnuaire(): void
    {
        $this->assertSame(
            'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            MicrosoftSsoProvider::endpointFor('common', 'authorize')
        );
        $this->assertSame(
            'https://login.microsoftonline.com/' . self::TENANT . '/v2.0/.well-known/openid-configuration',
            MicrosoftSsoProvider::discoveryUrlFor(self::TENANT)
        );
    }

    // ---- Refus Entra : nommer la vraie cause -------------------------------

    /**
     * Dans un annuaire d'entreprise, un refus Entra vient rarement d'un
     * identifiant mal recopié. Les trois causes les plus fréquentes se règlent
     * chez l'administrateur de l'annuaire, PAS dans MIA — et le message
     * générique « vérifiez les identifiants de l'application Azure » envoyait
     * chercher au mauvais endroit. Chaque code retenu doit donc nommer un
     * REMÈDE DIFFÉRENT.
     *
     */
    #[DataProvider('refusEntra')]
    public function testChaqueRefusEntraNommeSonRemede(string $corps, string $attendu): void
    {
        $message = MicrosoftOAuth::authErrorMessage($corps, 'https://mia.exemple.fr/auth/callback');
        $this->assertStringContainsString($attendu, $message, 'Corps : ' . $corps);
        // Jamais le corps brut du fournisseur dans un message d'écran.
        $this->assertStringNotContainsString('AADSTS', $message);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function refusEntra(): array
    {
        return [
            'consentement interdit aux utilisateurs' => ['error_description=AADSTS90094: …', 'consentement administrateur'],
            'permissions non consenties'             => ['AADSTS65001: The user or administrator …', 'consentement administrateur'],
            'refus sur l\'écran de consentement'     => ['AADSTS65004: User declined …', 'Consentement refusé'],
            'utilisateur non attribué'               => ['AADSTS50105: The signed in user …', 'attribution nominative'],
            'compte hors annuaire'                   => ['AADSTS50020: User account … does not exist', 'n\'appartient pas à l\'annuaire'],
            'plateforme SPA au lieu de Web'          => ['AADSTS9002326: Cross-origin token redemption …', 'monopage'],
            'client public sans secret'              => ['AADSTS7000218: The request body must contain …', 'client PUBLIC'],
            'permission inconnue'                    => ['AADSTS650053: The application asked for scope …', 'mal orthographiée'],
            'Graph absent de l\'annuaire'            => ['AADSTS500011: The resource principal …', 'Microsoft Graph introuvable'],
            'URI non déclarée'                       => ['AADSTS50011: The redirect URI …', 'URI de redirection non déclarée'],
            'secret périmé'                          => ['AADSTS7000222: The provided client secret …', 'expiré'],
        ];
    }

    /**
     * L'ORDRE compte : le filet `redirect_uri` est une sous-chaîne libre,
     * présente dans plusieurs descriptions Entra. Placé trop haut, il
     * répondrait « URI non déclarée » à un problème de consentement, et
     * l'administrateur chercherait des heures au mauvais endroit.
     */
    public function testLeFiletRedirectUriNeMasquePasUnCodePrecis(): void
    {
        $message = MicrosoftOAuth::authErrorMessage(
            'AADSTS90094: consentement requis — redirect_uri=https://mia.exemple.fr/auth/callback'
        );
        $this->assertStringContainsString('consentement administrateur', $message);
        $this->assertStringNotContainsString('URI de redirection non déclarée', $message);
    }

    /**
     * La page de CONNEXION n'a qu'un code d'erreur (le message du fournisseur
     * ne peut pas voyager dans l'URL, il serait ré-affiché tel quel) : ce code
     * doit au moins désigner le bon interlocuteur.
     */
    public function testUnRefusDeConsentementEstDistingueDUneMauvaiseConfiguration(): void
    {
        foreach (['AADSTS65001', 'AADSTS90094', 'AADSTS65004', 'AADSTS50105', 'consent_required'] as $code) {
            $this->assertTrue(MicrosoftOAuth::isConsentOrAssignmentError('x ' . $code . ' y'), $code);
        }
        foreach (['AADSTS50011', 'AADSTS7000215', 'AADSTS90002', ''] as $code) {
            $this->assertFalse(MicrosoftOAuth::isConsentOrAssignmentError($code), $code);
        }
    }

    /**
     * Le retour d'un fournisseur qui REFUSE porte `error`/`error_description`
     * mais AUCUN code : il tombait dans le garde « code ou state absent » et la
     * page affichait « Requête invalide », qui ne désigne rien. Chez Entra
     * c'est pourtant le refus le plus fréquent en entreprise.
     */
    public function testLeRefusDuFournisseurEstTraiteAvantLeGardeCodeManquant(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/AuthController.php');
        $refus  = strpos($source, "\$refus = (string)(\$_GET['error'] ?? '');");
        $garde  = strpos($source, "if (!\$code || !\$state) {");
        $this->assertIsInt($refus, 'Le traitement du refus fournisseur a disparu.');
        $this->assertIsInt($garde);
        $this->assertLessThan($garde, $refus, 'Le refus doit être traité AVANT le garde « code absent ».');

        // Le message du fournisseur reste dans les logs, jamais dans l'URL.
        $this->assertStringContainsString('MicrosoftOAuth::isConsentOrAssignmentError(', $source);
        $this->assertStringNotContainsString('error=' . "'" . ' . $desc', $source);

        // Et la page de connexion sait rendre les deux codes.
        $login = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/auth/login.php');
        $this->assertStringContainsString("'consent_required'", $login);
        $this->assertStringContainsString("'oauth_denied'", $login);
    }
}
