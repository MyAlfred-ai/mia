<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Connecteur Slack : les statiques pures (aucun réseau) et le câblage de bout
 * en bout.
 *
 * Ces gardes encodent les pièges propres à Slack, ceux qui ne font PAS tomber
 * le code mais rendent le connecteur faux en silence :
 *   - `ok:false` dans un HTTP 200 → une erreur lue comme un succès ;
 *   - des scopes séparés par des VIRGULES → toutes les capacités réputées
 *     absentes, ou pire, `chat:write` confondu avec `chat:write.public` ;
 *   - `user_scope` confondu avec `scope` → un jeton de bot, et aucun jeton
 *     utilisateur dans la réponse ;
 *   - un `ts` traité comme un nombre → un identifiant de message qui ne
 *     désigne plus rien ;
 *   - un historique rendu du plus récent au plus ancien → tout résumé à
 *     l'envers.
 */
final class UserSlackServiceTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($chemin, '/'));
    }

    /** Source d'un fichier PHP, commentaires retirés (garde textuelle honnête). */
    private static function codeSansCommentaires(string $chemin): string
    {
        $out = '';
        foreach (token_get_all(self::lire($chemin)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    // ===================== Scopes =====================

    /**
     * LE piège de comparaison : Slack sépare par des virgules, et `chat:write`
     * est un préfixe de `chat:write.public`. Une comparaison par sous-chaîne
     * afficherait « Publication ✓ » sur une connexion qui ne peut pas publier ;
     * un `preg_split('/\s+/')` recopié de Google rendrait un seul jeton
     * illisible et déclarerait TOUTES les capacités absentes.
     */
    public function testLesScopesSeComparentJetonParJeton(): void
    {
        $accorde = 'channels:history,channels:read,chat:write,search:read,users:read';

        $this->assertTrue(UserSlackService::scopeGranted($accorde, 'chat:write'));
        $this->assertTrue(UserSlackService::scopeGranted($accorde, 'search:read'));
        $this->assertTrue(UserSlackService::scopeGranted($accorde, 'users:read'));

        // Le piège de préfixe, dans les deux sens.
        $this->assertFalse(UserSlackService::scopeGranted($accorde, 'chat:write.public'));
        $this->assertFalse(UserSlackService::scopeGranted('chat:write.public', 'chat:write'));
        $this->assertFalse(UserSlackService::scopeGranted('users:read.email', 'users:read'));

        // Espaces tolérés : une valeur recopiée à la main ne doit pas faire
        // perdre ses capacités à une connexion valide.
        $this->assertTrue(UserSlackService::scopeGranted('chat:write search:read', 'search:read'));
        $this->assertFalse(UserSlackService::scopeGranted('', 'chat:write'));
    }

    public function testUneFamilleDeScopesSuffit(): void
    {
        $this->assertTrue(UserSlackService::anyScopeGranted('groups:history', UserSlackService::SCOPES_HISTORY));
        $this->assertTrue(UserSlackService::anyScopeGranted('im:read,channels:read', UserSlackService::SCOPES_LIST));
        $this->assertFalse(UserSlackService::anyScopeGranted('search:read', UserSlackService::SCOPES_HISTORY));
    }

    /**
     * Le consentement demande des scopes UTILISATEUR, et seulement ceux dont
     * MIA se sert. `users:read.email` élargirait l'accès sans usage ;
     * `chat:write.public` permettrait de publier dans un canal dont
     * l'utilisateur n'est pas membre, ce que le connecteur ne fait jamais.
     */
    public function testLesScopesDemandesSontCeuxQuOnUtilise(): void
    {
        $scopes = SlackMessagesOAuth::SCOPES;

        foreach (['search:read', 'channels:read', 'channels:history', 'users:read', 'chat:write'] as $attendu) {
            $this->assertContains($attendu, $scopes);
        }
        $this->assertNotContains('users:read.email', $scopes);
        $this->assertNotContains('chat:write.public', $scopes);
        $this->assertNotContains('im:write', $scopes, 'MIA n\'ouvre jamais de message direct.');
        $this->assertNotContains('files:write', $scopes);
        $this->assertNotContains('admin', $scopes);
    }

    // ===================== OAuth =====================

    /**
     * `user_scope`, jamais `scope` : passer les scopes dans `scope` produit un
     * jeton de BOT, la réponse est un succès et `authed_user.access_token` est
     * absent. Le connecteur se « connecte » puis ne peut rien lire.
     */
    public function testLeConsentementDemandeDesScopesUtilisateur(): void
    {
        $src = self::codeSansCommentaires('src/Auth/SlackUserOAuth.php');

        $this->assertStringContainsString("'user_scope'", $src);
        $this->assertDoesNotMatchRegularExpression(
            "/'scope'\s*=>\s*implode/",
            $src,
            'Les scopes doivent partir dans user_scope : dans `scope`, Slack rend un jeton de bot.'
        );
    }

    /**
     * La rotation des jetons est OPTIONNELLE chez Slack. Sans elle (le cas
     * courant), il n'y a aucun refresh token et le jeton utilisateur ne périme
     * pas : c'est lui qui est rangé comme jeton durable, et le « rafraîchi-
     * ssement » se fait sans appel réseau.
     */
    public function testLaRotationDesJetonsEstReconnueALaForme(): void
    {
        $this->assertTrue(SlackUserOAuth::isRotating('xoxe-1-abc'));
        $this->assertFalse(SlackUserOAuth::isRotating('xoxp-123-456'));
        $this->assertFalse(SlackUserOAuth::isRotating(''));

        // Jeton durable non rotatif : rendu tel quel, aucun réseau, et un scope
        // VIDE (Slack ne le redit pas) — prendre ce silence pour un retrait
        // ferait perdre ses outils à une connexion valide.
        $revoque = true;
        $frais = SlackUserOAuth::refreshAccessToken('xoxp-durable', $revoque);
        $this->assertIsArray($frais);
        $this->assertSame('xoxp-durable', $frais['access_token']);
        $this->assertSame('', $frais['scope']);
        $this->assertNull($frais['refresh_token']);
        $this->assertFalse($revoque);
    }

    /**
     * Slack range les jetons utilisateur à DEUX endroits : sous `authed_user`
     * à l'échange de code, à la racine au rafraîchissement. N'en lire qu'un
     * donne un connecteur qui se connecte puis meurt à la première expiration.
     */
    public function testLesJetonsSeLisentAuxDeuxEmplacements(): void
    {
        $echange = SlackUserOAuth::userTokensFromResponse([
            'ok' => true,
            'authed_user' => [
                'access_token'  => 'xoxp-user',
                'scope'         => 'chat:write,search:read',
                'expires_in'    => 43200,
            ],
        ]);
        $this->assertSame('xoxp-user', $echange['access_token']);
        // Sans rotation : le jeton durable EST le jeton d'accès.
        $this->assertSame('xoxp-user', $echange['refresh_token']);
        $this->assertSame('chat:write,search:read', $echange['scope']);

        $rafraichi = SlackUserOAuth::userTokensFromResponse([
            'ok'            => true,
            'access_token'  => 'xoxe.xoxp-nouveau',
            'refresh_token' => 'xoxe-suivant',
            'expires_in'    => 43200,
        ]);
        $this->assertSame('xoxe.xoxp-nouveau', $rafraichi['access_token']);
        $this->assertSame('xoxe-suivant', $rafraichi['refresh_token']);

        // Un succès SANS jeton utilisateur (scopes passés en `scope`) n'est pas
        // une connexion : il doit être rejeté, pas enregistré à moitié.
        $this->assertNull(SlackUserOAuth::userTokensFromResponse(['ok' => true, 'bot_token' => 'xoxb-…']));
        $this->assertNull(SlackUserOAuth::userTokensFromResponse(['ok' => false, 'error' => 'invalid_code']));
    }

    /**
     * Seuls les refus DURABLES valent reconnexion : marquer une limite de débit
     * « révoquée » couperait une connexion parfaitement valide.
     */
    public function testSeulsLesRefusDurablesValentReconnexion(): void
    {
        foreach (['invalid_auth', 'token_revoked', 'token_expired', 'account_inactive'] as $code) {
            $this->assertTrue(SlackUserOAuth::isRevocation($code), $code);
        }
        foreach (['ratelimited', 'internal_error', 'channel_not_found', 'missing_scope', ''] as $code) {
            $this->assertFalse(SlackUserOAuth::isRevocation($code), $code);
        }
    }

    /** Slack n'expose pas d'email sans scope dédié : le compte se nomme « @user · Équipe ». */
    public function testLeCompteConnecteSeNomme(): void
    {
        $this->assertSame('@alice · Acme', SlackUserOAuth::accountLabel(['user' => 'alice', 'team' => 'Acme']));
        $this->assertSame('@alice', SlackUserOAuth::accountLabel(['user' => '@alice']));
        $this->assertSame('Acme', SlackUserOAuth::accountLabel(['team' => 'Acme']));
        $this->assertSame('', SlackUserOAuth::accountLabel([]));
    }

    // ===================== Erreurs (ok:false dans un HTTP 200) =====================

    public function testLesErreursSlackSontLuesDansLeCorps(): void
    {
        $this->assertSame('channel_not_found', UserSlackService::slackErrorCode('{"ok":false,"error":"channel_not_found"}'));
        $this->assertSame('', UserSlackService::slackErrorCode('{"ok":true,"messages":[]}'));
        $this->assertSame('', UserSlackService::slackErrorCode('pas du json'));

        $this->assertTrue(UserSlackService::isScopeFailure('missing_scope'));
        $this->assertTrue(UserSlackService::isScopeFailure('not_allowed_token_type'));
        $this->assertFalse(UserSlackService::isScopeFailure('channel_not_found'));

        $this->assertTrue(UserSlackService::isNotFound('channel_not_found'));
        $this->assertTrue(UserSlackService::isNotFound('thread_not_found'));
        $this->assertFalse(UserSlackService::isNotFound('not_in_channel'));
    }

    /**
     * Les refus qui ont un REMÈDE PROPRE doivent le dire : « rejoins le canal »
     * n'est pas « reconnecte-toi », et c'est toute la valeur de ces messages.
     */
    public function testLesRefusAvecRemedePropreSontNommes(): void
    {
        $this->assertStringContainsString('membre de ce canal', (string)UserSlackService::specificSlackMessage('not_in_channel'));
        $this->assertStringContainsString('archivé', (string)UserSlackService::specificSlackMessage('is_archived'));
        $this->assertStringContainsString('trop long', (string)UserSlackService::specificSlackMessage('msg_too_long'));
        // Traités ailleurs (isNotFound / isScopeFailure) : pas de doublon ici.
        $this->assertNull(UserSlackService::specificSlackMessage('channel_not_found'));
        $this->assertNull(UserSlackService::specificSlackMessage('not_allowed_token_type'));
        $this->assertNull(UserSlackService::specificSlackMessage('inconnu_de_tous'));
    }

    /**
     * Aucun appel Slack ne doit sauter le contrôle de `ok` : `apiJson()` rend un
     * tableau sans lever tant que le statut HTTP est 200, ce que Slack renvoie
     * même sur un refus.
     */
    public function testAucunAppelNeContournLeControleDeOk(): void
    {
        $src = self::codeSansCommentaires('src/Slack/UserSlackService.php');
        $this->assertDoesNotMatchRegularExpression(
            '/\$this->apiJson\(/',
            $src,
            'Les appels Slack passent par slackGet()/slackPost(), qui contrôlent `ok`.'
        );
        $socle = self::codeSansCommentaires('src/Connectors/SlackUserApiService.php');
        $this->assertStringContainsString('function assertOk', $socle);
        $this->assertStringContainsString('UserDriveToken::markRevoked', $socle);
    }

    // ===================== Horodatages =====================

    /**
     * `ts` est une CHAÎNE : c'est l'identifiant du message. Le caster en nombre
     * perd les derniers chiffres et le fil visé n'existe plus.
     */
    public function testLHorodatageResteUneChaine(): void
    {
        $this->assertSame('1720000000.123456', UserSlackService::assertTs('1720000000.123456'));
        $this->assertSame('1720000000.123456', UserSlackService::assertTs('  1720000000.123456 '));

        foreach (['1720000000', '1720000000.12', 'abc', '', '1720000000,123456'] as $mauvais) {
            try {
                UserSlackService::assertTs($mauvais);
                $this->fail('« ' . $mauvais .' » aurait dû être refusé.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('1720000000.123456', $e->getMessage());
            }
        }
    }

    /**
     * Le lien permanent porte l'horodatage SANS son point : le rendre tel quel
     * désignerait un message inexistant.
     */
    public function testLeLienPermanentRedonneUnHorodatageUtilisable(): void
    {
        $url = 'https://acme.slack.com/archives/C04F3KJ1/p1720000000123456';
        $this->assertSame('1720000000.123456', UserSlackService::tsFromPermalink($url));
        $this->assertSame('', UserSlackService::tsFromPermalink('https://acme.slack.com/archives/C04F3KJ1'));

        // Et `assertTs` l'accepte : c'est ce que l'utilisateur colle.
        $this->assertSame('1720000000.123456', UserSlackService::assertTs($url));
    }

    public function testLaDateEstLaPartieEntiereDeLHorodatage(): void
    {
        $avant = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $this->assertSame('03/07/2024 11:33', UserSlackService::formatTs('1720006400.123456'));
            $this->assertSame('', UserSlackService::formatTs(''));
            $this->assertSame('', UserSlackService::formatTs('pas une date'));
        } finally {
            date_default_timezone_set($avant);
        }
    }

    // ===================== Canaux =====================

    public function testUneReferenceDeCanalSeReconnaitAsaForme(): void
    {
        $this->assertSame(['C04F3KJ1', ''], UserSlackService::parseChannelInput('C04F3KJ1'));
        $this->assertSame(['C04F3KJ1', ''], UserSlackService::parseChannelInput(
            'https://acme.slack.com/archives/C04F3KJ1/p1720000000123456'
        ));
        $this->assertSame(['', 'projet-x'], UserSlackService::parseChannelInput('#projet-x'));
        $this->assertSame(['', 'projet-x'], UserSlackService::parseChannelInput('projet-x'));
        // Un nom en minuscules n'est JAMAIS pris pour un identifiant, même long.
        $this->assertSame(['', 'comptabilite'], UserSlackService::parseChannelInput('comptabilite'));
    }

    public function testLesNomsDeCanauxSeComparentSansAccentNiDiese(): void
    {
        $this->assertSame('reunions', UserSlackService::normaliserNom('#Réunions'));
        $this->assertSame('projet-x', UserSlackService::normaliserNom(' Projet-X '));
    }

    /** Les arrivées et départs sont du bruit ; un message d'application, non. */
    public function testLesMessagesDeServiceSontEcartes(): void
    {
        $this->assertTrue(UserSlackService::estBruit(['subtype' => 'channel_join']));
        $this->assertTrue(UserSlackService::estBruit(['subtype' => 'pinned_item']));
        $this->assertFalse(UserSlackService::estBruit(['text' => 'bonjour']));
        $this->assertFalse(
            UserSlackService::estBruit(['bot_id' => 'B1', 'text' => 'Déploiement terminé']),
            'Une notification d\'application est souvent ce qu\'on vient chercher.'
        );
    }

    /**
     * L'historique d'un canal arrive du plus RÉCENT au plus ancien : sans
     * inversion, « résume la journée » se lit à l'envers. Un fil, lui, arrive
     * déjà dans l'ordre — l'inverser le casserait.
     */
    public function testLHistoriqueEstRemisDansLOrdreDeLecture(): void
    {
        $src = self::codeSansCommentaires('src/Slack/UserSlackService.php');
        $this->assertMatchesRegularExpression(
            '/conversations\.history.*?\$tokenBudget,\s*true\s*\)/s',
            $src,
            'conversations.history doit être inversé.'
        );
        $this->assertMatchesRegularExpression(
            '/conversations\.replies.*?\$tokenBudget,\s*false\s*\)/s',
            $src,
            'conversations.replies est déjà dans l\'ordre de lecture.'
        );
        $this->assertStringContainsString('array_reverse($messages)', $src);
    }

    // ===================== Publication =====================

    public function testLaPublicationEstValideeAvantToutAppel(): void
    {
        $ok = UserSlackService::normalizePostArgs([
            'canal' => '#projet-x',
            'texte' => "**Compte rendu**\n\nVoir [le doc](https://exemple.fr/doc).",
            'repondre_a' => '1720000000.123456',
        ]);
        $this->assertSame('#projet-x', $ok['canal']);
        $this->assertSame('1720000000.123456', $ok['repondre_a']);
        // Converti DÈS ICI : la carte d'approbation doit montrer ce qui partira.
        $this->assertStringContainsString('*Compte rendu*', $ok['texte']);
        $this->assertStringContainsString('<https://exemple.fr/doc|le doc>', $ok['texte']);

        foreach ([
            ['texte' => 'coucou'],                          // canal absent
            ['canal' => '#a', 'texte' => '   '],            // texte vide
            ['canal' => '#a', 'texte' => 'x', 'repondre_a' => 'pas-un-ts'],
        ] as $mauvais) {
            $this->expectExceptionOnCall($mauvais);
        }
    }

    /**
     * Le texte est converti UNE fois, par le handler. Le reconvertir à
     * l'exécution abîmerait le message sans la moindre erreur : le gras
     * `*titre*` deviendrait de l'italique, et les chevrons de nos liens
     * seraient échappés — `<url|libellé>` publié en toutes lettres.
     */
    public function testLaConversionNaLieuQuUneFois(): void
    {
        $mrkdwn = UserSlackService::normalizePostArgs([
            'canal' => '#a',
            'texte' => "**Titre**\n\n[le doc](https://exemple.fr/doc)",
        ])['texte'];

        // Ce que ferait une seconde conversion, si elle avait lieu.
        $abime = SlackMrkdwn::toMrkdwn($mrkdwn);
        $this->assertNotSame($mrkdwn, $abime, 'Sans ce piège, le test ne prouverait rien.');
        $this->assertStringContainsString('&lt;https', $abime);

        // L'exécution, elle, ne fait que valider.
        $execute = UserSlackService::assertPostArgs(['canal' => '#a', 'texte' => $mrkdwn]);
        $this->assertSame($mrkdwn, $execute['texte']);

        // Et c'est bien `assertPostArgs` que la publication appelle.
        $src = self::codeSansCommentaires('src/Slack/UserSlackService.php');
        $this->assertMatchesRegularExpression(
            '/function postMessage\(array \$args\).*?self::assertPostArgs\(\$args\)/s',
            $src
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function postMessage\(array \$args\).*?self::normalizePostArgs\(/s',
            $src
        );
    }

    private function expectExceptionOnCall(array $args): void
    {
        try {
            UserSlackService::normalizePostArgs($args);
            $this->fail('Arguments invalides acceptés : ' . json_encode($args));
        } catch (\RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    /**
     * La carte d'approbation doit dire ce qui distingue Slack de toutes les
     * autres écritures du chat : le message part VRAIMENT, tout de suite, et
     * MIA ne pourra plus rien en faire.
     */
    public function testLaCarteDApprobationAnnonceUnePublicationImmediate(): void
    {
        $apercu = UserSlackService::actionPreview('envoyer_message_slack', [
            'canal' => 'projet-x',
            'texte' => 'Bonjour tout le monde',
        ]);

        $this->assertSame('#projet-x', $apercu['fichier']);
        $this->assertSame('/assets/img/slack.svg', $apercu['icone']);
        $this->assertSame('Bonjour tout le monde', $apercu['apercu']);
        $impacts = implode(' ', $apercu['impacts']);
        $this->assertStringContainsString('EN VOTRE NOM', $impacts);
        $this->assertStringContainsString('immédiate', $impacts);
        $this->assertStringContainsString('ni modifier ni supprimer', $impacts);

        $reponse = UserSlackService::actionPreview('envoyer_message_slack', [
            'canal' => '#projet-x', 'texte' => 'ok', 'repondre_a' => '1720000000.123456',
        ]);
        $this->assertStringContainsString('fil', $reponse['action']);
    }

    /**
     * `chat:write` autorise côté API la modification et la suppression des
     * messages de l'utilisateur. Ce sont des garde-fous APPLICATIFS qui tiennent
     * la promesse : aucun appel de ce genre ne doit exister dans le code.
     */
    public function testAucuneModificationNiSuppressionDeMessage(): void
    {
        $src = self::codeSansCommentaires('src/Slack/UserSlackService.php');
        foreach (['chat.delete', 'chat.update', 'conversations.leave', 'conversations.kick',
                  'files.delete', 'admin.'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $src, "« $interdit » n'a rien à faire ici.");
        }
        // Et la seule écriture existe bien.
        $this->assertStringContainsString('chat.postMessage', $src);
    }

    // ===================== mrkdwn =====================

    /**
     * Slack n'écrit pas du Markdown : le gras y est à UNE astérisque et
     * l'italique au tiret bas. Publié tel quel, le Markdown du modèle
     * s'afficherait avec ses astérisques.
     */
    public function testLeMarkdownDevientDuMrkdwn(): void
    {
        $this->assertSame('*gras*', SlackMrkdwn::toMrkdwn('**gras**'));
        $this->assertSame('_italique_', SlackMrkdwn::toMrkdwn('*italique*'));
        $this->assertSame('_italique_', SlackMrkdwn::toMrkdwn('_italique_'));
        $this->assertSame('~barré~', SlackMrkdwn::toMrkdwn('~~barré~~'));
        $this->assertSame('*Titre*', SlackMrkdwn::toMrkdwn('## Titre'));
        $this->assertSame('<https://x.fr|libellé>', SlackMrkdwn::toMrkdwn('[libellé](https://x.fr)'));
        $this->assertSame("• un\n• deux", SlackMrkdwn::toMrkdwn("- un\n- deux"));
        $this->assertSame('> cité', SlackMrkdwn::toMrkdwn('> cité'));
    }

    /**
     * L'ordre d'échappement est LE piège : Slack exige `&`, `<` et `>`
     * échappés, mais nos propres chevrons de lien doivent rester des chevrons.
     */
    public function testLEchappementPrecedeLaFabricationDesLiens(): void
    {
        $this->assertSame('a &lt; b &amp; c', SlackMrkdwn::toMrkdwn('a < b & c'));
        $this->assertSame(
            '<https://x.fr|a &amp; b>',
            SlackMrkdwn::toMrkdwn('[a & b](https://x.fr)'),
            'Le libellé est échappé, les chevrons du lien ne le sont pas.'
        );
        // Une balise du modèle ne peut pas s'injecter dans le message.
        $this->assertStringNotContainsString('<script>', SlackMrkdwn::toMrkdwn('<script>alert(1)</script>'));
    }

    /** Un bloc de code n'est ni échappé ni reformaté : il part tel quel. */
    public function testLeCodeTraverseIntact(): void
    {
        $source = "Voici :\n```\nif (a && b) { x = **y**; }\n```";
        $sortie = SlackMrkdwn::toMrkdwn($source);
        $this->assertStringContainsString('if (a && b) { x = **y**; }', $sortie);
        $this->assertSame('`a && b`', SlackMrkdwn::toMrkdwn('`a && b`'));
    }

    /**
     * En lecture, un message Slack est plein d'identifiants. Donnés bruts au
     * modèle, il les recopie : l'utilisateur lit `<@U04F3KJ1>` au lieu d'un nom.
     */
    public function testLeMrkdwnRedevientLisible(): void
    {
        $noms = ['U04F3KJ1' => 'Alice Martin', 'C02AB' => 'general'];

        $this->assertSame('@Alice Martin a écrit', SlackMrkdwn::toText('<@U04F3KJ1> a écrit', $noms));
        $this->assertSame('@alice a écrit', SlackMrkdwn::toText('<@U04F3KJ1|alice> a écrit', $noms));
        // Identifiant inconnu : rendu tel quel plutôt qu'un nom inventé.
        $this->assertSame('@U99ZZZ', SlackMrkdwn::toText('<@U99ZZZ>'));
        $this->assertSame('#general', SlackMrkdwn::toText('<#C02AB>', $noms));
        $this->assertSame('#projet-x', SlackMrkdwn::toText('<#C07XY|projet-x>'));
        $this->assertSame('@here', SlackMrkdwn::toText('<!here>'));
        $this->assertSame('@here', SlackMrkdwn::toText('<!here|here>'));
        $this->assertSame('le doc (https://x.fr)', SlackMrkdwn::toText('<https://x.fr|le doc>'));
        $this->assertSame('https://x.fr', SlackMrkdwn::toText('<https://x.fr>'));
        $this->assertSame('a@b.fr', SlackMrkdwn::toText('<mailto:a@b.fr>'));
        // Déséchappement en DERNIER : un `&lt;` redevenu `<` trop tôt aurait été
        // pris pour l'ouverture d'un lien.
        $this->assertSame('a < b & c', SlackMrkdwn::toText('a &lt; b &amp; c'));
    }

    /**
     * Seuls les identifiants SANS libellé demandent un appel à Slack : résoudre
     * ceux qui portent déjà leur nom ferait un aller-retour réseau par message.
     */
    public function testSeulsLesIdentifiantsNusSontAResoudre(): void
    {
        $this->assertSame(
            ['U04F3KJ1', 'C02AB'],
            SlackMrkdwn::unresolvedIds('<@U04F3KJ1> voir <#C02AB> et <@U77|deja-nomme>')
        );
        $this->assertSame([], SlackMrkdwn::unresolvedIds('aucun identifiant ici'));
    }

    // ===================== Câblage =====================

    /**
     * Câblage complet : un connecteur à moitié branché n'échoue pas, il
     * n'apparaît nulle part — la panne la plus difficile à voir.
     */
    public function testLeConnecteurEstBrancheDeBoutEnBout(): void
    {
        $ligne = ConnectorRegistry::row(ConnectorRegistry::SLACK);
        $this->assertIsArray($ligne);
        $this->assertSame('slack', $ligne['family']);
        $this->assertSame(ConnectorRegistry::SURFACE_CHAT, $ligne['surface']);
        $this->assertSame(SlackMessagesOAuth::class, $ligne['oauth']);
        $this->assertSame(UserSlackService::class, $ligne['service']);
        $this->assertSame(UserDriveToken::PROVIDER_SLACK, $ligne['token']);
        $this->assertSame(UserSlackService::SETTING_ENABLED, $ligne['setting']);
        // La carte renvoie vers l'écran qui porte les identifiants : sans lui,
        // l'administrateur n'a nulle part où les saisir.
        $this->assertSame('/admin/connectors/slack', $ligne['settings_url']);

        // Routes : connexion, retour, déconnexion, et l'écran d'administration.
        $routes = self::lire('public/index.php');
        foreach (['/settings/slack/connect', '/settings/slack/callback', '/settings/slack/disconnect',
                  '/admin/connectors/slack', '/admin/connectors/slack/settings',
                  '/admin/connectors/slack/test'] as $route) {
            $this->assertStringContainsString("'" . $route . "'", $routes, "Route absente : $route");
        }

        // Le retour OAuth partage l'URL du login : seul le préfixe de state
        // l'aiguille. Un oubli ici rend la connexion muette.
        $this->assertStringContainsString("'slack_'", self::lire('src/Controllers/AuthController.php'));
        $this->assertContains('slack_', SsoRegistry::CONNECTOR_PREFIXES);

        // Chat : opt-in du message, outils, approbation.
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertStringContainsString("\$_POST['slack_access'] ?? '0'", $chat);
        $this->assertStringContainsString("'slack_access'", $chat);
        foreach (['lister_canaux_slack', 'rechercher_slack', 'lire_canal_slack',
                  'lire_fil_slack', 'envoyer_message_slack'] as $outil) {
            $this->assertStringContainsString("'" . $outil . "'", $chat, "Outil absent du chat : $outil");
        }
        $this->assertStringContainsString('UserSlackService::actionPreview', $chat);
        $this->assertStringContainsString('$slack->postMessage(', $chat);

        // Trace : un type sans libellé ni famille visuelle rendrait une ligne
        // muette. ChatController dépend de vendor/, il n'est pas dans
        // tests/bootstrap.php — même chargement à la demande qu'ActionTraceTest.
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        foreach (['slack_search', 'slack_read', 'envoyer_message_slack'] as $type) {
            $this->assertArrayHasKey($type, ChatController::ACTION_LABELS);
            $this->assertArrayHasKey($type, ChatController::ACTION_ICONS);
        }
        $this->assertStringContainsString(
            '.msg-trace-icon[data-icon="chat"]',
            self::lire('public/assets/css/main.css'),
            'Une famille d\'icône sans glyphe rendrait un carré plein.'
        );

        // Interfaces : ligne du menu « + », carte de la page Connexions.
        $this->assertStringContainsString('plusMenuSlackRow', self::lire('templates/chat/index.php'));
        $this->assertStringContainsString('slackAccessToggle', self::lire('public/assets/js/chat.js'));
        $this->assertStringContainsString('/settings/slack/connect', self::lire('templates/settings/connections.php'));
    }

    /**
     * Pas d'autoloader : un socle chargé APRÈS sa sous-classe ne plante que là
     * où le fichier est requis — c'est-à-dire, pour le chat, dans le worker.
     */
    public function testLesClassesSontChargeesDansLeBonOrdre(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $fichier) {
            $source = self::lire($fichier);
            foreach ([
                ['/src/Slack/SlackApp.php', '/src/Auth/SlackUserOAuth.php'],
                ['/src/Auth/SlackUserOAuth.php', '/src/Auth/SlackMessagesOAuth.php'],
                ['/src/Connectors/SlackUserApiService.php', '/src/Slack/UserSlackService.php'],
                ['/src/Slack/SlackMrkdwn.php', '/src/Slack/UserSlackService.php'],
            ] as [$avant, $apres]) {
                $a = strpos($source, $avant);
                $b = strpos($source, $apres);
                $this->assertNotFalse($a, "$fichier ne charge pas $avant.");
                $this->assertNotFalse($b, "$fichier ne charge pas $apres.");
                $this->assertLessThan($b, $a, "$fichier : $avant doit précéder $apres.");
            }
        }
    }

    /**
     * Les identifiants de l'application vivent en BASE, jamais dans une
     * constante d'environnement : le worker ne voit pas l'environnement de
     * `app`, et le chat s'exécute dans le worker. C'est la panne muette déjà
     * payée côté Google.
     */
    public function testLesIdentifiantsViventEnBase(): void
    {
        $src = self::codeSansCommentaires('src/Slack/SlackApp.php');
        $this->assertStringContainsString('Settings::get(', $src);
        $this->assertStringContainsString('Encryption::', $src);
        $this->assertDoesNotMatchRegularExpression('/\bdefined\s*\(/', $src);

        // Secret jamais réaffiché : un champ vide vaut « conserver ».
        $this->assertStringContainsString('function setClientSecret', $src);
        $this->assertMatchesRegularExpression('/if\s*\(\$secret === \'\'\)\s*\{\s*return;/', $src);
    }

    /**
     * Les expressions déclencheuses ne doivent pas sur-déclencher : « canal »
     * seul désigne aussi un canal de distribution ou d'irrigation.
     */
    public function testLesExpressionsDeclencheusesRestentSpecifiques(): void
    {
        $defauts = ConnectorRegistry::defaultTriggers(ConnectorRegistry::SLACK);
        $this->assertNotEmpty($defauts);
        foreach (['canal', 'message', 'messages', 'fil', 'equipe', 'équipe'] as $tropLarge) {
            $this->assertNotContains($tropLarge, $defauts, "« $tropLarge » seul sur-déclencherait.");
        }
        $this->assertContains('slack', $defauts);
    }

    // ===================== Écran « Application Slack » =====================

    /** Noms des outils décrits au modèle pour un jeu de capacités. */
    private static function nomsOutils(bool $list, bool $search, bool $read, bool $post): array
    {
        return array_map(
            static fn(array $def): string => (string)$def['name'],
            SlackTools::definitionsFor($list, $search, $read, $post)
        );
    }

    /**
     * Le tableau des autorisations de l'écran dit ce qui se passe RÉELLEMENT :
     * chaque scope demandé y figure une fois, et les outils annoncés pour une
     * ligne sont exactement ceux que les gardes canList / canSearch / canRead /
     * canPost exposent quand seule cette autorisation est accordée. Un scope
     * ajouté à l'OAuth sans ligne, ou un outil renommé, fait tomber ce test.
     */
    public function testLeGuideDesAutorisationsSuitLesOutilsReellementExposes(): void
    {
        $guide   = UserSlackService::scopeGuide();
        $couverts = [];
        $outilsCites = [];
        foreach ($guide as $ligne) {
            $this->assertNotSame('', trim($ligne['usage']));
            $this->assertNotSame('', trim($ligne['sans']));
            foreach ($ligne['scopes'] as $scope) {
                $this->assertNotContains($scope, $couverts, "Scope décrit deux fois : $scope");
                $couverts[] = $scope;
            }

            $accorde = implode(',', $ligne['scopes']);
            $exposes = self::nomsOutils(
                UserSlackService::anyScopeGranted($accorde, UserSlackService::SCOPES_LIST),
                UserSlackService::scopeGranted($accorde, UserSlackService::SCOPE_SEARCH),
                UserSlackService::anyScopeGranted($accorde, UserSlackService::SCOPES_HISTORY),
                UserSlackService::scopeGranted($accorde, UserSlackService::SCOPE_WRITE)
            );
            $this->assertSame($ligne['outils'], $exposes, 'Outils annoncés ≠ outils exposés pour ' . $accorde);
            $outilsCites = array_merge($outilsCites, $ligne['outils']);
        }

        $demandes = SlackMessagesOAuth::SCOPES;
        sort($demandes);
        sort($couverts);
        $this->assertSame($demandes, $couverts, 'Chaque autorisation demandée doit avoir sa ligne, et aucune autre.');

        // Tous les outils du connecteur sont expliqués quelque part.
        $tous = self::nomsOutils(true, true, true, true);
        sort($tous);
        sort($outilsCites);
        $this->assertSame($tous, $outilsCites);
        $this->assertSame([], self::nomsOutils(false, false, false, false));
    }

    /**
     * Ce que l'écran montre comme « consigne reçue par le modèle » est le texte
     * du chat, pas une copie : les méthodes d'instance délèguent aux statiques.
     */
    public function testLaConsigneMontreeEstCelleDuChat(): void
    {
        $src = self::codeSansCommentaires('src/Agent/Tools/SlackTools.php');
        $this->assertStringContainsString(
            'return self::definitionsFor($slack->canList(), $slack->canSearch(), $slack->canRead(), $slack->canPost());',
            $src
        );
        $this->assertStringContainsString('return self::guidanceFor($slack->canPost());', $src);

        $avec = SlackTools::guidanceFor(true);
        $sans = SlackTools::guidanceFor(false);
        $this->assertStringContainsString('envoyer_message_slack', $avec);
        $this->assertStringNotContainsString('envoyer_message_slack', $sans);

        // L'extrait « lecture seule » commence à une frontière de phrase, n'est
        // pas dans la version complète, et termine la version lecture seule.
        $fin = SlackTools::readOnlyGuidanceTail();
        $this->assertNotSame('', $fin);
        $this->assertMatchesRegularExpression('/^\p{Lu}/u', $fin, 'Extrait coupé au milieu d\'une phrase.');
        $this->assertStringNotContainsString($fin, $avec);
        $this->assertStringEndsWith($fin, rtrim($sans));
        $this->assertTrue(mb_check_encoding($fin, 'UTF-8'));
    }

    /**
     * Manifeste à coller dans Slack : l'URL de retour et les autorisations
     * UTILISATEUR, aucun scope de bot, et un nom dans la limite de Slack.
     */
    public function testLeManifesteDeclareLURLEtLesAutorisationsUtilisateur(): void
    {
        $m = SlackApp::manifest('MIA', 'https://mia.example.com/auth/callback', SlackMessagesOAuth::SCOPES);
        $this->assertSame('MIA', $m['display_information']['name']);
        $this->assertSame(['https://mia.example.com/auth/callback'], $m['oauth_config']['redirect_urls']);
        $this->assertSame(SlackMessagesOAuth::SCOPES, $m['oauth_config']['scopes']['user']);
        $this->assertArrayNotHasKey('bot', $m['oauth_config']['scopes'], 'MIA ne demande aucun jeton de bot.');
        $this->assertLessThanOrEqual(140, mb_strlen($m['display_information']['description']));

        $long = SlackApp::manifest(str_repeat('Assistant ', 10), 'https://x.test/auth/callback', []);
        $this->assertSame(SlackApp::MANIFEST_NAME_MAX, mb_strlen($long['display_information']['name']));
        $this->assertSame('MIA', SlackApp::manifest("  \n ", 'https://x.test', [])['display_information']['name']);

        // Rendu JSON lisible par Slack : barres obliques non échappées.
        $json = json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('"https://mia.example.com/auth/callback"', (string)$json);
    }

    /**
     * L'écran guide de bout en bout : manifeste, URL de retour, identifiants,
     * tableau des autorisations, parcours dans une conversation et textes
     * exacts reçus par le modèle — tirés des sources uniques, jamais recopiés.
     */
    public function testLEcranGuideJusquAuPrompt(): void
    {
        $ctrl = self::lire('src/Controllers/AdminController.php');
        foreach (['UserSlackService::scopeGuide()', 'SlackApp::manifest(', 'SlackTools::guidanceFor(true)',
                  'SlackTools::readOnlyGuidanceTail()', 'SlackTools::definitionsFor(true, true, true, true)',
                  'ExternalContent::rule()', 'UserDriveToken::countConnected(UserDriveToken::PROVIDER_SLACK)'] as $source) {
            $this->assertStringContainsString($source, $ctrl, "Source absente du contrôleur : $source");
        }

        $tpl = self::lire('templates/admin/connectors-slack.php');
        foreach (['id="slackManifest"', 'id="slackRedirectUri"', 'id="identifiants"', 'id="autorisations"',
                  'id="conversation"', 'id="prompt"', '$scopeGuide', '$promptGuidance', '$promptReadOnlyTail',
                  '$promptToolDefs', '$promptSafetyRule', 'User Token Scopes', 'From a manifest',
                  'App Credentials', '$chatLabel'] as $attendu) {
            $this->assertStringContainsString($attendu, $tpl, "Absent de l'écran : $attendu");
        }
        // Enregistrement manuel préservé (exception assumée à l'auto-save).
        $this->assertStringContainsString('id="slackAppForm" data-autosave="off"', $tpl);
        $this->assertStringContainsString('action="/admin/connectors/slack/settings"', $tpl);
        $this->assertStringContainsString("'/admin/connectors/slack/test'", $tpl);
        // Aucune liste d'autorisations recopiée en dur dans le gabarit.
        $this->assertStringNotContainsString("'search:read'", $tpl);
        $this->assertStringContainsString('.slack-scope-table', self::lire('public/assets/css/main.css'));
    }
}
