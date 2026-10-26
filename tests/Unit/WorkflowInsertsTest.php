<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Les pièces du dossier insérées TELLES QUELLES dans ce qu'une étape écrit —
 * cf. CLAUDE.md « Workflows » (puce Pièces insérées). « Envoie un mail qui
 * contient @[Message Slack hebdomadaire] et l'image @[Image] » partait avec le
 * seul NOM de la pièce, sans image (constaté en recette le 2026-10-26) :
 * désormais le modèle écrit un repère, le serveur le remplace par le contenu
 * exact et intègre l'image au message.
 */
final class WorkflowInsertsTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::use('fr');
    }

    private static function src(string $path): string
    {
        return (string)file_get_contents(ROOT . '/' . $path);
    }

    private static function def(string $write = 'auto'): array
    {
        return WorkflowSpec::normalize([
            'start' => ['fiche_name' => 'Demande', 'fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text']]],
            'nodes' => [
                ['key' => 'redac', 'type' => 'step', 'title' => 'Rédiger', 'instruction' => 'Rédige.', 'output' => ['key' => 'message_slack', 'name' => 'Message Slack hebdomadaire', 'form' => 'text']],
                ['key' => 'illus', 'type' => 'create', 'kind' => 'image', 'title' => 'Illustrer', 'instruction' => 'Une image.', 'output' => ['key' => 'image', 'name' => 'Image']],
                ['key' => 'mail', 'type' => 'action', 'title' => 'Préparer ou envoyer un email', 'target' => 'gmail', 'write' => $write,
                    'instruction' => 'Envoie un mail à alice@example.com qui contient @{message_slack} et l\'image @{image}, pour @{depart.client}.'],
                ['key' => 'lire', 'type' => 'step', 'title' => 'Résumer', 'instruction' => 'Résume @{message_slack}.', 'output' => ['key' => 'resume', 'name' => 'Résumé', 'form' => 'text']],
            ],
            'end' => ['title' => 'Fait', 'pieces' => []],
        ]);
    }

    private static function artifacts(): array
    {
        return [
            'depart' => ['key' => 'depart', 'name' => 'Demande', 'form' => 'fiche', 'content' => ['fields' => ['client' => 'Ville de Lyon']]],
            'message_slack' => ['key' => 'message_slack', 'name' => 'Message Slack hebdomadaire', 'form' => 'text',
                'content' => ['text' => "*Les nouvelles de la semaine*\n\n- **MIA 1.5.1** en recette\n- Bienvenue à Lina"]],
            'image' => ['key' => 'image', 'name' => 'Image', 'form' => 'image', 'content' => ['url' => '/assets/img/generated/x.png', 'prompt' => 'Une équipe']],
        ];
    }

    private static function fill(array $args, ?array $def = null, ?callable $exists = null): array
    {
        $def ??= self::def();
        $arts = self::artifacts();
        return WorkflowInserts::fill('creer_brouillon_gmail', $args, WorkflowInserts::forPass($def, WorkflowSpec::node($def, 'mail'), $arts),
            $def, $arts, 77, $exists ?? static fn(): bool => true);
    }

    // ------------------------------------------------------------------
    // Ce que l'étape peut insérer
    // ------------------------------------------------------------------

    public function testSeuleUneBriqueQuiEcritInsere(): void
    {
        $def = self::def();
        $arts = self::artifacts();
        $pass = WorkflowInserts::forPass($def, WorkflowSpec::node($def, 'mail'), $arts);
        $this->assertEqualsCanonicalizing(['message_slack' => 'message_slack', 'image' => 'image', 'depart' => 'depart'], $pass['map']);
        $this->assertSame(['image'], $pass['images'], 'L’image citée par la consigne est retenue.');
        $this->assertSame(['map' => [], 'images' => []], WorkflowInserts::forPass($def, WorkflowSpec::node($def, 'lire'), $arts), 'Une étape qui n’écrit pas n’insère rien.');
        // Dans une boucle, la clé RÉELLE (« image#2 ») est gardée.
        $arts['image']['key'] = 'image#2';
        $this->assertSame('image#2', WorkflowInserts::forPass($def, WorkflowSpec::node($def, 'mail'), $arts)['map']['image']);
    }

    public function testLaConsigneApprendLesReperes(): void
    {
        $def = self::def();
        $note = WorkflowPrompt::note('Nouvelles', $def, WorkflowSpec::node($def, 'mail'), self::artifacts());
        $this->assertStringContainsString('<<< PIÈCE « Message Slack hebdomadaire » (texte) — repère @{message_slack}', $note);
        $this->assertStringContainsString('« Image » → @{image} (image', $note);
        $this->assertStringContainsString('@{depart.client} (Client)', $note, 'Un champ de Fiche a son repère.');
        $this->assertStringContainsString('INSÉRER UNE PIÈCE TELLE QUELLE', $note);
        $this->assertStringContainsString('La consigne cite « Message Slack hebdomadaire » (@{message_slack}), « Image » (@{image})', $note);
        $this->assertStringContainsString('N\'écris jamais toi-même de balise <img>', $note);
        // Une étape qui n'écrit pas : aucun repère.
        $lire = WorkflowPrompt::note('Nouvelles', $def, WorkflowSpec::node($def, 'lire'), self::artifacts());
        $this->assertStringNotContainsString('repère', $lire);
        $this->assertStringNotContainsString('INSÉRER UNE PIÈCE', $lire);
    }

    // ------------------------------------------------------------------
    // Le remplissage
    // ------------------------------------------------------------------

    public function testLeRepereDevientLeContenuExactEtLImageEstIntegree(): void
    {
        $out = self::fill(['destinataire' => 'alice@example.com', 'objet' => 'Nouvelles — @{message_slack}',
            'corps' => "Pour l'équipe, grâce à MIA.\n\n@{message_slack}\n\n@{image}\n\nClient : @{depart.client}"]);
        $this->assertStringContainsString('- **MIA 1.5.1** en recette', $out['corps']);
        $this->assertStringContainsString('Client : Ville de Lyon', $out['corps']);
        $this->assertStringContainsString('Nouvelles — Message Slack hebdomadaire', $out['objet'], 'Dans l’objet, le NOM de la pièce.');
        $this->assertMatchesRegularExpression('/\[\[mia-image:img1\.[0-9a-f]{10}@mia\]\]/', $out['corps']);
        $this->assertCount(1, $out['_wf_attach']);
        $ref = $out['_wf_attach'][0];
        $this->assertSame(['run_id' => 77, 'piece' => 'image', 'name' => 'Image'], ['run_id' => $ref['run_id'], 'piece' => $ref['piece'], 'name' => $ref['name']]);
        $this->assertStringContainsString($ref['cid'], $out['corps']);

        $html = MailHtml::withInlineImages(MailHtml::toHtml($out['corps']), MailDraftReview::inlineImages($out));
        $this->assertStringContainsString('<strong>MIA 1.5.1</strong>', $html);
        $this->assertStringContainsString('<img src="cid:' . $ref['cid'] . '" alt="Image"', $html);
        $this->assertStringNotContainsString('[[mia-image', $html);
    }

    public function testLeNomEcritALaFaconDeLEditeurEstReconnu(): void
    {
        $out = self::fill(['corps' => "Voici : @[Message Slack hebdomadaire]\n\n@[image]"]);
        $this->assertStringContainsString('Bienvenue à Lina', $out['corps']);
        $this->assertStringContainsString('[[mia-image:', $out['corps']);
    }

    public function testUnCorpsEnHtmlRecoitDuHtml(): void
    {
        $out = self::fill(['corps' => '<p>Pour l’équipe :</p><div>@{message_slack}</div>']);
        $this->assertStringContainsString('<li><strong>MIA 1.5.1</strong> en recette</li>', $out['corps'], 'Du Markdown dans un corps HTML s’afficherait en clair.');
    }

    public function testLImageCiteeMaisNonPlaceeEstAjouteeALaFin(): void
    {
        // Le cas de la recette : le modèle n'avait écrit que le nom de la pièce.
        $out = self::fill(['corps' => "# Message Slack hebdomadaire\n\nCe message est à destination de l’équipe."]);
        $this->assertMatchesRegularExpression('/\n\n\[\[mia-image:[^\]]+\]\]$/', $out['corps']);
        $this->assertCount(1, $out['_wf_attach']);
        // Fichier disparu (élagage) : rien n'est promis.
        $gone = self::fill(['corps' => 'Bonjour @{image}'], null, static fn(): bool => false);
        $this->assertStringContainsString('Bonjour « Image »', $gone['corps']);
        $this->assertArrayNotHasKey('_wf_attach', $gone);
    }

    public function testRienNEstInventeNiDesigneParLeModele(): void
    {
        // Repère d'une pièce que l'étape ne lit pas, ou inconnue : laissé tel quel.
        $out = self::fill(['corps' => '@{resume} @{inconnue} [[mia-image:img9.0000000000@mia]]']);
        $this->assertStringContainsString('@{resume} @{inconnue}', $out['corps']);
        $html = MailHtml::withInlineImages(MailHtml::toHtml($out['corps']), MailDraftReview::inlineImages($out));
        $this->assertStringNotContainsString('img9', $html, 'Un repère d’image que le serveur n’a pas posé est retiré.');
        // Hors email : une image reste son nom, aucune pièce jointe.
        $def = self::def();
        $arts = self::artifacts();
        $slack = WorkflowInserts::fill('envoyer_message_slack', ['canal' => '#equipe', 'texte' => "@{message_slack}\n@{image}"],
            WorkflowInserts::forPass($def, WorkflowSpec::node($def, 'mail'), $arts), $def, $arts, 77, static fn(): bool => true);
        $this->assertStringContainsString('Bienvenue à Lina', $slack['texte']);
        $this->assertStringContainsString('« Image »', $slack['texte']);
        $this->assertArrayNotHasKey('_wf_attach', $slack);
        // Hors workflow : rien ne change.
        $this->assertSame(['corps' => '@{x}'], WorkflowInserts::forTurn('creer_brouillon_gmail', ['corps' => '@{x}'], null, 1));
    }

    // ------------------------------------------------------------------
    // Pièces qui citent des pièces (constaté en recette : la news Slack,
    // rédigée par une étape qui écrit, contenait « - @{veille_ia_de_la_semaine} »)
    // ------------------------------------------------------------------

    private static function recette(): array
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fiche_name' => 'Demande', 'fields' => [['key' => 'auteur', 'label' => 'Auteur', 'type' => 'text']]],
            'nodes' => [
                ['key' => 'veille', 'type' => 'step', 'title' => 'Veille', 'instruction' => 'Cherche.', 'output' => ['key' => 'veille_ia_de_la_semaine', 'name' => 'Veille IA de la semaine', 'form' => 'list']],
                ['key' => 'synthese', 'type' => 'step', 'title' => 'Synthèse', 'instruction' => 'Synthétise.', 'output' => ['key' => 'synthese_myalfred_semain', 'name' => 'Synthèse MyAlfred', 'form' => 'text']],
                ['key' => 'news', 'type' => 'step', 'title' => 'Rédiger la news', 'write' => 'auto',
                    'instruction' => 'Rédige la news à partir de @{veille_ia_de_la_semaine} et @{synthese_myalfred_semain}.',
                    'output' => ['key' => 'message_slack_hebdomadai', 'name' => 'Message Slack hebdomadaire', 'form' => 'text']],
                ['key' => 'mail', 'type' => 'action', 'title' => 'Email', 'target' => 'gmail', 'write' => 'auto',
                    'instruction' => 'Envoie un mail qui contient @{message_slack_hebdomadai}.'],
            ],
            'end' => ['title' => 'Fait', 'pieces' => []],
        ]);
        $arts = [
            'veille_ia_de_la_semaine' => ['key' => 'veille_ia_de_la_semaine', 'name' => 'Veille IA de la semaine', 'form' => 'list', 'content' => ['items' => ['Claude Opus 5.5 sort', 'GPT-6.1 Sol en promotion']]],
            'synthese_myalfred_semain' => ['key' => 'synthese_myalfred_semain', 'name' => 'Synthèse MyAlfred', 'form' => 'text', 'content' => ['text' => 'MIA 1.5.1 est en **recette**.']],
            'message_slack_hebdomadai' => ['key' => 'message_slack_hebdomadai', 'name' => 'Message Slack hebdomadaire', 'form' => 'text',
                'content' => ['text' => "Bonjour à tous !\n\n**IA de la semaine**\n- @{veille_ia_de_la_semaine}\n\n**Chez MyAlfred**\n- @{synthese_myalfred_semain}"]],
        ];
        return [$def, $arts];
    }

    public function testUnePieceQuiCiteDesPiecesEstRemplieATourDeRole(): void
    {
        [$def, $arts] = self::recette();
        $out = WorkflowInserts::fill('creer_brouillon_gmail', ['destinataire' => 'alice@example.com', 'corps' => "Pour l'équipe :\n\n@{message_slack_hebdomadai}"],
            WorkflowInserts::forPass($def, WorkflowSpec::node($def, 'mail'), $arts), $def, $arts, 6, static fn(): bool => true);
        $this->assertStringNotContainsString('@{', $out['corps'], 'Plus aucun repère dans l’email : ' . $out['corps']);
        $this->assertStringContainsString('- Claude Opus 5.5 sort', $out['corps']);
        $this->assertStringContainsString('MIA 1.5.1 est en **recette**.', $out['corps']);
        // Corps en HTML : la pièce et ce qu'elle cite, convertis UNE fois.
        $html = WorkflowInserts::fill('creer_brouillon_gmail', ['corps' => '<p>Pour l’équipe :</p>@{message_slack_hebdomadai}'],
            WorkflowInserts::forPass($def, WorkflowSpec::node($def, 'mail'), $arts), $def, $arts, 6, static fn(): bool => true);
        $this->assertStringContainsString('<li>Claude Opus 5.5 sort</li>', $html['corps']);
        $this->assertStringNotContainsString('@{', $html['corps']);
        $this->assertStringNotContainsString('&lt;', $html['corps'], 'Rien n’est converti deux fois.');
    }

    public function testDeuxPiecesQuiSeCitentNeTournentPasEnRond(): void
    {
        $pieces = [
            'a' => ['key' => 'a', 'name' => 'Pièce A', 'form' => 'text', 'fields' => []],
            'b' => ['key' => 'b', 'name' => 'Pièce B', 'form' => 'text', 'fields' => []],
            'c' => ['key' => 'c', 'name' => 'Pièce C', 'form' => 'text', 'fields' => []],
        ];
        $contents = ['a' => 'A voit @{b}', 'b' => 'B voit @{a} et @{c}', 'c' => 'C voit @{c}'];
        $lookup = static fn(string $k): ?array => isset($contents[$k]) ? ['key' => $k, 'content' => ['text' => $contents[$k]]] : null;
        $same = static fn(string $k, string $real): ?callable => $lookup;
        $this->assertSame('A voit B voit « Pièce A » et C voit « Pièce C »', WorkflowInserts::expandText('@{a}', $pieces, $lookup, null, false, $same));
        // Sans règle d'auteur, le contenu inséré garde ses repères.
        $this->assertSame('A voit @{b}', WorkflowInserts::expandText('@{a}', $pieces, $lookup));
        // Une chaîne plus longue que MAX_DEPTH s'arrête au nom.
        $chain = [];
        $pieces2 = [];
        for ($i = 0; $i <= WorkflowInserts::MAX_DEPTH + 2; $i++) {
            $pieces2['p' . $i] = ['key' => 'p' . $i, 'name' => 'P' . $i, 'form' => 'text', 'fields' => []];
            $chain['p' . $i] = $i . ' @{p' . ($i + 1) . '}';
        }
        $chainLookup = static fn(string $k): ?array => isset($chain[$k]) ? ['key' => $k, 'content' => ['text' => $chain[$k]]] : null;
        $deep = WorkflowInserts::expandText('@{p0}', $pieces2, $chainLookup, null, false, static fn(): ?callable => $chainLookup);
        $this->assertStringContainsString('« P' . WorkflowInserts::MAX_DEPTH . ' »', $deep);
        $this->assertStringNotContainsString('@{', $deep);
    }

    public function testUnRepereDerriereUnePuceGardeLaListeLisible(): void
    {
        $pieces = [
            'l' => ['key' => 'l', 'name' => 'Liste', 'form' => 'list', 'fields' => []],
            't' => ['key' => 't', 'name' => 'Texte', 'form' => 'text', 'fields' => []],
        ];
        $data = ['l' => ['items' => ['Un', 'Deux']], 't' => ['text' => "Premier paragraphe.\n\nSecond."]];
        $lookup = static fn(string $k): ?array => isset($data[$k]) ? ['key' => $k, 'content' => $data[$k]] : null;
        // Une liste prend la place de la puce ; en retrait, elle le garde.
        $this->assertSame("Titre\n- Un\n- Deux", WorkflowInserts::expandText("Titre\n- @{l}", $pieces, $lookup));
        $this->assertSame("- A\n  - Un\n  - Deux", WorkflowInserts::expandText("- A\n  * @{l}", $pieces, $lookup));
        // Un texte reste dans sa puce, lignes suivantes en retrait.
        $this->assertSame("1. Premier paragraphe.\n\n   Second.", WorkflowInserts::expandText('1. @{t}', $pieces, $lookup));
        // Ailleurs dans la ligne : tel quel ; repère inconnu : intact.
        $this->assertSame('- Voir Premier paragraphe.' . "\n\n" . 'Second. ici', WorkflowInserts::expandText('- Voir @{t} ici', $pieces, $lookup));
        $this->assertSame('- @{x}', WorkflowInserts::expandText('- @{x}', $pieces, $lookup));
    }

    public function testLaPieceRemiseParUneEtapeGardeSonContenuRempli(): void
    {
        [$def, $arts] = self::recette();
        $pieces = WorkflowSpec::pieces($def);
        $news = WorkflowSpec::node($def, 'news');
        $text = WorkflowInserts::expandContent($def, $news, $pieces['message_slack_hebdomadai'], $arts['message_slack_hebdomadai']['content'], $arts, null);
        $this->assertStringNotContainsString('@{', $text['text']);
        $this->assertStringContainsString('- GPT-6.1 Sol en promotion', $text['text']);
        // Jamais la pièce elle-même ; un repère inconnu reste.
        $self = WorkflowInserts::expandContent($def, $news, $pieces['message_slack_hebdomadai'], ['text' => 'Moi : @{message_slack_hebdomadai}, @{inconnue}'], $arts, null);
        $this->assertSame('Moi : @{message_slack_hebdomadai}, @{inconnue}', $self['text']);
        // Une liste, un tableau, une fiche : chaque valeur.
        $list = WorkflowInserts::expandContent($def, $news, ['key' => 'l', 'form' => 'list'], ['items' => ['Voir @{synthese_myalfred_semain}', 'Tout : @{veille_ia_de_la_semaine}']], $arts, null);
        $this->assertSame(['Voir MIA 1.5.1 est en **recette**.', 'Tout : - Claude Opus 5.5 sort - GPT-6.1 Sol en promotion'], $list['items'], 'Un élément reste sur une ligne.');
        $table = WorkflowInserts::expandContent($def, $news, ['key' => 't', 'form' => 'table'], ['rows' => [['Sujet'], ['@{veille_ia_de_la_semaine}']]], $arts, null);
        $this->assertSame('Veille IA de la semaine', $table['rows'][1][0], 'Une cellule reçoit le nom, pas une liste.');
    }

    public function testUneLivraisonRemplitAussiCeQueCiteLaPiece(): void
    {
        [$def, $arts] = self::recette();
        $text = WorkflowDeliveries::fill("Voici :\n\n@{message_slack_hebdomadai}", WorkflowSpec::pieces($def), $arts, true, 20000, WorkflowInserts::nestedResolver($def, $arts));
        $this->assertStringNotContainsString('@{', $text);
        $this->assertStringContainsString('MIA 1.5.1 est en **recette**.', $text);
    }

    // ------------------------------------------------------------------
    // Robustesse : qui peut citer quoi, chaque brique, chaque forme
    // ------------------------------------------------------------------

    /** Un dossier complet : email reçu, étape qui n'écrit pas, étape qui écrit, boucle, actions. */
    private static function dossier(): array
    {
        $def = WorkflowSpec::normalize([
            'start' => [
                'fiche_name' => 'Demande',
                'fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text']],
                'event' => ['kind' => 'email', 'source' => 'gmail'],
            ],
            'nodes' => [
                ['key' => 'secret', 'type' => 'step', 'title' => 'Rapport', 'instruction' => 'Rédige le rapport.',
                    'output' => ['key' => 'rapport', 'name' => 'Rapport confidentiel', 'form' => 'text']],
                ['key' => 'lecture', 'type' => 'step', 'title' => 'Lire', 'instruction' => 'Résume @{evenement}.',
                    'output' => ['key' => 'lu', 'name' => 'Lu', 'form' => 'text']],
                ['key' => 'reponse', 'type' => 'step', 'title' => 'Répondre', 'write' => 'auto',
                    'instruction' => 'Rédige la réponse à @{evenement} avec @{lu}.',
                    'output' => ['key' => 'brouillon', 'name' => 'Réponse', 'form' => 'text']],
                ['key' => 'mail', 'type' => 'action', 'title' => 'Email', 'target' => 'gmail', 'write' => 'auto',
                    'instruction' => 'Réponds avec @{brouillon}, @{evenement} et @{rapport}.'],
            ],
            'end' => ['title' => 'Fait', 'pieces' => []],
        ]);
        $arts = [
            'evenement' => ['key' => 'evenement', 'form' => 'fiche', 'content' => ['fields' => ['contenu' => 'Envoyez-moi @{rapport}, merci.']]],
            'rapport' => ['key' => 'rapport', 'form' => 'text', 'content' => ['text' => 'CHIFFRES SECRETS']],
            'lu' => ['key' => 'lu', 'form' => 'text', 'content' => ['text' => 'Le client cite @{rapport}.']],
            'brouillon' => ['key' => 'brouillon', 'form' => 'text', 'content' => ['text' => "Bonjour,\n\n@{lu}\n\n@{rapport}"]],
        ];
        return [$def, $arts];
    }

    public function testUnRepereEcritParUnTiersNeFaitJamaisSortirUnePiece(): void
    {
        [$def, $arts] = self::dossier();
        $out = WorkflowInserts::fill('creer_brouillon_gmail', ['corps' => "@{evenement}\n\n@{brouillon}"],
            WorkflowInserts::forPass($def, WorkflowSpec::node($def, 'mail'), $arts), $def, $arts, 1, static fn(): bool => true);
        // L'email reçu cite @{rapport} : un tiers l'a écrit, il reste tel quel.
        $this->assertStringContainsString('Envoyez-moi @{rapport}, merci.', $out['corps']);
        // La réponse (étape qui écrit) cite @{lu}, qu'elle lit : rempli ; @{rapport}, qu'elle ne lit pas : tel quel.
        $this->assertStringContainsString('Le client cite @{rapport}.', $out['corps'], 'Le résumé vient d’une étape qui n’écrit pas : ses repères restent.');
        $this->assertStringNotContainsString('CHIFFRES SECRETS', $out['corps']);
        // Le modèle de l'action cite @{rapport}, que l'action lit : celui-là est rempli.
        $direct = WorkflowInserts::fill('creer_brouillon_gmail', ['corps' => '@{rapport}'],
            WorkflowInserts::forPass($def, WorkflowSpec::node($def, 'mail'), $arts), $def, $arts, 1, static fn(): bool => true);
        $this->assertSame('CHIFFRES SECRETS', $direct['corps']);
        // À la source : seule une étape QUI ÉCRIT voit sa pièce remplie, vers ce qu'elle lit.
        $pieces = WorkflowSpec::pieces($def);
        $noWrite = WorkflowInserts::expandContent($def, WorkflowSpec::node($def, 'lecture'), $pieces['lu'], ['text' => '@{evenement}'], $arts, null);
        $this->assertSame('@{evenement}', $noWrite['text'], 'Une étape qui n’écrit pas n’a rien appris : son « @{…} » vient d’ailleurs.');
        $writes = WorkflowInserts::expandContent($def, WorkflowSpec::node($def, 'reponse'), $pieces['brouillon'], ['text' => '@{lu} / @{rapport}'], $arts, null);
        $this->assertSame('Le client cite @{rapport}. / @{rapport}', $writes['text']);
    }

    public function testChaqueOutilRecoitLaPieceDansSaForme(): void
    {
        [$def, $arts] = self::recette();
        $node = WorkflowSpec::node($def, 'mail');
        $run = static fn(string $tool, array $args): array => WorkflowInserts::fill($tool, $args, WorkflowInserts::forPass($def, $node, $arts), $def, $arts, 3, static fn(): bool => true);
        // Slack : le texte du modèle est déjà en mrkdwn ; la pièce y entre convertie, une seule fois.
        $slack = $run('envoyer_message_slack', ['canal' => '#equipe', 'texte' => "*News*\n• @{message_slack_hebdomadai}"]);
        $this->assertStringContainsString('*IA de la semaine*', $slack['texte'], 'Le gras Markdown devient le gras de Slack.');
        $this->assertStringNotContainsString('**', $slack['texte']);
        $this->assertStringContainsString('• Claude Opus 5.5 sort', $slack['texte']);
        $this->assertStringNotContainsString('• •', $slack['texte'], 'Une liste insérée derrière une puce prend la place de la ligne.');
        // Note de tâche, description d'évènement : du texte brut.
        foreach ([['creer_tache', 'notes'], ['creer_tache_todo', 'notes'], ['creer_evenement_agenda', 'description'], ['creer_evenement_agenda_outlook', 'description']] as [$tool, $arg]) {
            $out = $run($tool, [$arg => "Voir :\n\n@{message_slack_hebdomadai}"]);
            $this->assertStringContainsString('IA de la semaine', $out[$arg], $tool);
            $this->assertStringContainsString('MIA 1.5.1 est en recette.', $out[$arg], $tool);
            $this->assertStringNotContainsString('**', $out[$arg], $tool . ' : ni astérisques ni dièses à l’écran.');
        }
        // Document Drive, message Teams, sujet Gamma : du Markdown, converti par leur service.
        foreach ([['creer_google_doc', 'contenu'], ['envoyer_message_teams', 'texte'], ['generer_presentation_gamma', 'sujet']] as [$tool, $arg]) {
            $out = $run($tool, [$arg => '@{message_slack_hebdomadai}']);
            $this->assertStringContainsString('MIA 1.5.1 est en **recette**.', $out[$arg], $tool);
            $this->assertStringNotContainsString('@{', $out[$arg], $tool);
        }
        // Une ligne (titre, lieu) : le nom de la pièce.
        $this->assertSame('News — Message Slack hebdomadaire', $run('creer_evenement_agenda', ['titre' => 'News — @{message_slack_hebdomadai}'])['titre']);
        // Un tableur : chaque cellule est une ligne, la valeur d'un champ y entre.
        [$def2, $arts2] = self::dossier();
        $cells = WorkflowInserts::fill('creer_google_sheet', ['titre' => 'Suivi', 'valeurs' => json_encode([['Pièce', 'Contenu'], ['@{rapport}', '@{evenement.contenu}']])],
            WorkflowInserts::forPass($def2, WorkflowSpec::node($def2, 'mail'), $arts2), $def2, $arts2, 1);
        $this->assertSame([['Pièce', 'Contenu'], ['Rapport confidentiel', 'Envoyez-moi @{rapport}, merci.']], $cells['valeurs']);
    }

    public function testUnEmailEnHtmlEchappeLesValeurs(): void
    {
        $def = self::def();
        $arts = self::artifacts();
        $arts['depart']['content']['fields']['client'] = 'Dupont & <Fils>';
        $out = self::fillWith($def, $arts, ['corps' => '<p>Pour @{depart.client} :</p>@{message_slack}']);
        $this->assertStringContainsString('Pour Dupont &amp; &lt;Fils&gt; :', $out['corps']);
        $this->assertStringContainsString('<li><strong>MIA 1.5.1</strong> en recette</li>', $out['corps']);
        // En Markdown, la valeur reste telle quelle (le service la convertira).
        $this->assertStringContainsString('Pour Dupont & <Fils>', self::fillWith($def, $arts, ['corps' => 'Pour @{depart.client}'])['corps']);
    }

    private static function fillWith(array $def, array $arts, array $args): array
    {
        return WorkflowInserts::fill('creer_brouillon_gmail', $args, WorkflowInserts::forPass($def, WorkflowSpec::node($def, 'mail'), $arts), $def, $arts, 77, static fn(): bool => true);
    }

    public function testUnTexteNeRecoitPasPlusQueSaPlace(): void
    {
        $pieces = [];
        $data = [];
        $text = '';
        for ($i = 0; $i < 6; $i++) {
            $pieces['p' . $i] = ['key' => 'p' . $i, 'name' => 'P' . $i, 'form' => 'text', 'fields' => []];
            $data['p' . $i] = ['text' => str_repeat('x', 40000)];
            $text .= '@{p' . $i . '} ';
        }
        $out = WorkflowInserts::expandText($text, $pieces, static fn(string $k): ?array => isset($data[$k]) ? ['key' => $k, 'content' => $data[$k]] : null);
        $this->assertLessThanOrEqual(WorkflowInserts::TOTAL_MAX + 200, mb_strlen($out));
        $this->assertStringContainsString('« P5 »', $out, 'Au-delà de la place, la pièce est nommée.');
    }

    public function testUneEtapeDeBoucleCiteLesPiecesDeSonTour(): void
    {
        $def = WorkflowSpec::normalize([
            'start' => ['fiche_name' => 'Demande', 'fields' => [['key' => 'client', 'label' => 'Client', 'type' => 'text']]],
            'nodes' => [
                ['key' => 'liste', 'type' => 'step', 'title' => 'Lister', 'instruction' => 'Liste.', 'output' => ['key' => 'sujets', 'name' => 'Sujets', 'form' => 'list']],
                ['key' => 'tour', 'type' => 'loop', 'mode' => 'each', 'title' => 'Chaque sujet', 'over' => 'sujets',
                    'item' => ['key' => 'sujet', 'name' => 'Sujet'],
                    'body' => [
                        ['key' => 'note', 'type' => 'step', 'title' => 'Noter', 'instruction' => 'Note sur @{sujet}.', 'output' => ['key' => 'fiche', 'name' => 'Note', 'form' => 'text']],
                        ['key' => 'ecrit', 'type' => 'step', 'title' => 'Écrire', 'write' => 'auto', 'instruction' => 'Écris avec @{fiche} et @{sujet}.',
                            'output' => ['key' => 'mot', 'name' => 'Mot', 'form' => 'text']],
                    ],
                    'output' => ['key' => 'mots', 'name' => 'Mots']],
            ],
            'end' => ['title' => 'Fait', 'pieces' => []],
        ]);
        $all = [
            'sujet#1' => ['key' => 'sujet#1', 'content' => ['text' => 'Premier']],
            'sujet#2' => ['key' => 'sujet#2', 'content' => ['text' => 'Second']],
            'fiche#1' => ['key' => 'fiche#1', 'content' => ['text' => 'Note 1']],
            'fiche#2' => ['key' => 'fiche#2', 'content' => ['text' => 'Note 2']],
        ];
        $pieces = WorkflowSpec::pieces($def);
        $out = WorkflowInserts::expandContent($def, WorkflowSpec::node($def, 'ecrit'), $pieces['mot'], ['text' => '@{sujet} : @{fiche}'], $all, 2);
        $this->assertSame('Second : Note 2', $out['text'], 'Le tour 2 voit les pièces du tour 2.');
        // Une pièce de tour insérée plus tard : ses repères suivent le même tour.
        $all['mot#2'] = ['key' => 'mot#2', 'content' => ['text' => '@{sujet}']];
        $nested = WorkflowInserts::nestedResolver($def, $all);
        $this->assertSame('Second', WorkflowInserts::renderPiece('mot', $pieces['mot'], $all['mot#2']['content'], $pieces, $nested, 'mot#2'));
    }

    // ------------------------------------------------------------------
    // L'email assemblé
    // ------------------------------------------------------------------

    public function testGmailIntegreLImageAuMessage(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $mime = UserGmailService::buildDraftMime('alice@example.com', 'Nouvelles', "Bonjour\n\n[[mia-image:img1.ab12cd34ef@mia]]", '', [], '',
            [['name' => 'Image.png', 'mime' => 'image/png', 'bytes' => $png, 'cid' => 'img1.ab12cd34ef@mia']]);
        $this->assertStringContainsString('Content-Type: multipart/related; type="multipart/alternative"', $mime);
        $this->assertStringContainsString("Content-ID: <img1.ab12cd34ef@mia>\r\nContent-Disposition: inline;", $mime);
        $html = '';
        foreach (preg_split('/\r\n--/', $mime) as $part) {
            if (str_contains($part, 'text/html')) {
                $html = base64_decode(preg_replace('/\s+/', '', substr($part, strpos($part, "\r\n\r\n") + 4)));
            }
        }
        $this->assertStringContainsString('<img src="cid:img1.ab12cd34ef@mia"', $html);
        // Sans image intégrée : le message d'avant, à l'octet près dans sa forme.
        $plain = UserGmailService::buildDraftMime('a@ex.fr', 'X', 'corps');
        $this->assertStringContainsString('Content-Type: multipart/alternative;', $plain);
        $this->assertStringNotContainsString('multipart/related', $plain);
        // Une pièce jointe ordinaire reste une pièce jointe.
        $mixed = UserGmailService::buildDraftMime('a@ex.fr', 'X', 'corps', '', [], '', [['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => '%PDF', 'cid' => 'img1.x@mia']]);
        $this->assertStringNotContainsString('Content-ID', $mixed, 'Un `cid` sur un fichier qui n’est pas une image est ignoré.');
        $this->assertStringContainsString('Content-Disposition: attachment;', $mixed);
    }

    public function testOutlookIntegreLImageAuMessage(): void
    {
        $payload = UserOutlookService::buildDraftPayload('alice@example.com', 'Nouvelles', "Bonjour\n\n[[mia-image:img1.ab12cd34ef@mia]]", '',
            [['cid' => 'img1.ab12cd34ef@mia', 'name' => 'Image']]);
        $this->assertStringContainsString('<img src="cid:img1.ab12cd34ef@mia" alt="Image"', $payload['body']['content']);
        $graph = OutgoingAttachments::graphPayload(OutgoingAttachments::normalize([['name' => 'Image.png', 'mime' => 'image/png', 'bytes' => 'x', 'cid' => 'img1.ab12cd34ef@mia']])[0]);
        $this->assertTrue($graph['isInline']);
        $this->assertSame('img1.ab12cd34ef@mia', $graph['contentId']);
        $this->assertArrayNotHasKey('isInline', OutgoingAttachments::graphPayload(['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => 'x']));
    }

    public function testLaCarteEtLHistoriqueMontrentLImage(): void
    {
        $args = self::fill(['destinataire' => 'alice@example.com', 'corps' => "Bonjour\n\n@{image}"]);
        $block = MailDraftReview::block('gmail', $args);
        $this->assertStringContainsString('<em>[Image]</em>', $block['corps_html'], 'La carte nomme l’image à sa place.');
        $this->assertStringNotContainsString('[[mia-image', $block['corps_html']);
        $view = WorkflowContentView::action(['tool' => 'creer_brouillon_gmail', 'args' => $args, 'preview' => []], ['image' => 'Image'], ['image' => '/assets/img/generated/x.png']);
        $this->assertStringContainsString('<img class="wf-view-inline" src="/assets/img/generated/x.png"', $view, 'L’historique montre l’image envoyée.');
        $this->assertStringNotContainsString('<dt>Pièces jointes</dt>', $view, 'Une image intégrée n’est pas une pièce jointe.');
    }

    // ------------------------------------------------------------------
    // Câblage
    // ------------------------------------------------------------------

    public function testLesLivraisonsEtLaCorrectionSuiventLaMemeRegle(): void
    {
        [$def, $arts] = self::dossier();
        $pieces = WorkflowSpec::pieces($def);
        // Sans règle d'auteur, une livraison insère la pièce telle quelle.
        $this->assertSame("Bonjour,\n\n@{lu}\n\n@{rapport}", WorkflowDeliveries::fill('@{brouillon}', $pieces, $arts, true));
        // Avec : ce que l'étape lisait est rempli, le reste non ; l'email reçu garde ses « @{…} ».
        $nested = WorkflowInserts::nestedResolver($def, $arts);
        $this->assertSame("Bonjour,\n\nLe client cite @{rapport}.\n\n@{rapport}", WorkflowDeliveries::fill('@{brouillon}', $pieces, $arts, true, 20000, $nested));
        $this->assertStringContainsString('Envoyez-moi @{rapport}', WorkflowDeliveries::fill('@{evenement}', $pieces, $arts, true, 20000, $nested));

        $deliveries = self::src('src/Workflow/WorkflowDeliveries.php');
        $this->assertSame(2, substr_count($deliveries, 'WorkflowInserts::nestedResolver('), 'Email et Slack remplissent les pièces citées.');
        $this->assertStringContainsString('WorkflowInserts::renderPiece($k, $pieces[$k]', $deliveries, 'Une pièce ajoutée à une publication Slack aussi.');
        $controller = self::src('src/Controllers/WorkflowController.php');
        $correct = substr($controller, (int)strpos($controller, 'public function correctPiece('));
        $correct = substr($correct, 0, (int)strpos($correct, 'public function download('));
        $this->assertLessThan(strpos($correct, 'WorkflowRun::saveArtifact('), strpos($correct, 'WorkflowInserts::expandContent('),
            'Une pièce corrigée à la validation est remplie comme à sa remise.');
    }

    public function testLeRemplissageEstBrancheAuDepotDeLAction(): void
    {
        $chat = self::src('src/Controllers/ChatController.php');
        $deposit = substr($chat, (int)strpos($chat, '$depositConnectorWrite = function'));
        $deposit = substr($deposit, 0, (int)strpos($deposit, '$preview = match'));
        $this->assertLessThan(
            strpos($deposit, 'WorkflowInserts::forTurn('),
            strpos($deposit, 'WorkflowDeliveries::stripInternal('),
            'Les arguments internes du modèle sont retirés AVANT que le serveur pose les siens.'
        );
        $engine = self::src('src/Workflow/WorkflowEngine.php');
        $this->assertStringContainsString("'inserts'        => WorkflowInserts::forPass(\$def, \$node, \$artifacts),", $engine);
        $save = substr($engine, (int)strpos($engine, '$read = ArtifactContract::extract($piece, $text);'));
        $this->assertLessThan(strpos($save, 'WorkflowRun::saveArtifact('), strpos($save, 'WorkflowInserts::expandContent('),
            'La pièce d’une étape est remplie AVANT d’entrer au dossier.');
        $deliveries = self::src('src/Workflow/WorkflowDeliveries.php');
        $this->assertStringContainsString("\$file['cid'] = (string)\$ref['cid'];", $deliveries);
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('WorkflowInserts.php', self::src($f), $f);
        }
    }
}
