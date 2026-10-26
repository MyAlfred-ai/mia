<?php

declare(strict_types=1);

/**
 * Nourrir le cerveau MyBrain depuis une conversation (lot 3) — l'outil
 * `nourrir_mybrain`, sa carte d'approbation, le dépôt et son suivi.
 *
 * Trois verrous, dans cet ordre, et aucun ne remplace l'autre :
 *   1. la CLÉ porte le droit « nourrir » côté MyBrain (le maximum) ;
 *   2. la PERSONNE « peut nourrir » : un partage le lui accorde — case par
 *      groupe, décision produit du 2026-09-15 (KnowledgeBase::canFeed) ;
 *   3. l'utilisateur APPROUVE la carte dans MIA, puis un humain tranche la
 *      proposition dans la revue de MyBrain. Rien n'entre jamais directement
 *      dans le wiki.
 * Le dépôt part au nom de la personne (`pour` = son email) : la question de
 * la revue lui est adressée si elle a un compte MyBrain.
 *
 * Deux sections, comme MyBrainContext : les FORMES, pures (validation,
 * définition d'outil, aperçu, messages), puis les APPELS (qui peut nourrir,
 * dépôt, suivi).
 */
final class MyBrainFeed
{
    /** Bornes du texte déposé — celles de MyBrain (MIN_TEXTE_DEPOT, MAX_TEXTE_DEPOT) : l'erreur arrive AVANT la carte. */
    public const MIN_TEXT  = 20;
    public const MAX_TEXT  = 120000;
    public const MAX_TITLE = 300;

    /** Suivi d'un dépôt : l'analyse par MyBrain va de quelques secondes à quelques minutes, file comprise. */
    public const POLL_INTERVAL_SEC = 10;
    public const POLL_TIMEOUT_SEC  = 1200;

    /** Suivis ratés d'affilée tolérés (MyBrain qui redémarre) avant de rendre la main. */
    public const MAX_NETWORK_FAILURES = 6;

    // ===================== Formes pures =====================

    /**
     * Les arguments de l'outil, validés et bornés. PURE. L'exception porte un
     * message pour le MODÈLE (il corrige avant que la carte n'existe).
     *
     * @return array{titre: string, texte: string, lien: string, url: string}
     * @throws \InvalidArgumentException
     */
    public static function normalizeArgs(array $args): array
    {
        $titre = trim((string)($args['titre'] ?? ''));
        $texte = trim((string)($args['texte'] ?? ''));
        $lien  = trim((string)($args['lien'] ?? ''));
        $url   = trim((string)($args['url'] ?? ''));

        if ($texte !== '' && $url !== '') {
            throw new \InvalidArgumentException("Donne « texte » OU « url », pas les deux (la source d'un texte va dans « lien »).");
        }
        if ($texte === '' && $url === '') {
            throw new \InvalidArgumentException("Rien à transmettre : donne le « texte » à proposer au cerveau (ou l'« url » d'une page web).");
        }
        if ($texte !== '' && $titre === '') {
            throw new \InvalidArgumentException("Donne un « titre » court et explicite au texte.");
        }
        if ($texte !== '' && mb_strlen($texte) < self::MIN_TEXT) {
            throw new \InvalidArgumentException('Texte trop court pour être analysé (' . self::MIN_TEXT . ' caractères au moins).');
        }
        if (mb_strlen($texte) > self::MAX_TEXT) {
            throw new \InvalidArgumentException('Texte trop long (' . self::MAX_TEXT . ' caractères au plus) : propose-le en plusieurs parties.');
        }
        foreach (['lien' => $lien, 'url' => $url] as $champ => $adresse) {
            if ($adresse !== '' && preg_match('#^https?://[^\s/]+#i', $adresse) !== 1) {
                throw new \InvalidArgumentException("« " . $champ . " » doit être une adresse http(s).");
            }
        }

        return [
            'titre' => mb_substr($titre, 0, self::MAX_TITLE),
            'texte' => $texte,
            'lien'  => mb_substr($lien, 0, 2000),
            'url'   => mb_substr($url, 0, 2000),
        ];
    }

    /**
     * La définition de l'outil. PURE. Le paramètre `base` n'apparaît que si
     * plusieurs cerveaux peuvent être nourris.
     *
     * @param list<array<string, mixed>> $feedKbs bases que CETTE personne peut nourrir
     */
    public static function toolDefinition(array $feedKbs): array
    {
        $names = array_values(array_map(static fn(array $kb): string => (string)($kb['name'] ?? ''), $feedKbs));
        $base  = count($names) > 1
            ? ['base' => [
                'type'        => 'string',
                'enum'        => $names,
                'description' => "Le cerveau à nourrir : plusieurs sont engagés dans cette conversation.",
            ]]
            : [];

        return [
            'name'        => 'nourrir_mybrain',
            'description' => "Propose un texte au cerveau d'équipe MyBrain pour qu'il en tire des faits datés et "
                . "sourcés : compte rendu de réunion, décision, information sur un client, un projet ou une "
                . "personne. Rien n'est écrit directement : l'utilisateur approuve d'abord une carte dans MIA, "
                . "puis le texte part dans la file de revue de MyBrain, où une personne le valide. À n'utiliser "
                . "QUE sur demande explicite de l'utilisateur.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'titre' => [
                        'type'        => 'string',
                        'description' => "Titre court et explicite (« Réunion Exemple du 12 septembre — décisions »).",
                    ],
                    'texte' => [
                        'type'        => 'string',
                        'description' => "Le texte à transmettre, autoportant : qui, quoi, quand, et d'où vient "
                            . "l'information. De " . self::MIN_TEXT . " à " . self::MAX_TEXT . " caractères. Vide "
                            . "si tu transmets une page web (« url »).",
                    ],
                    'lien' => [
                        'type'        => 'string',
                        'description' => "Facultatif : l'adresse http(s) de la source du texte.",
                    ],
                    'url' => [
                        'type'        => 'string',
                        'description' => "Facultatif, à la place de « texte » : l'adresse http(s) d'une page web "
                            . "que MyBrain lira lui-même.",
                    ],
                ] + $base,
                'required'   => ['titre'],
            ],
        ];
    }

    /** La consigne de l'outil (boucle d'outils seulement). PURE. */
    public static function toolGuidance(): string
    {
        return "\n\nNourrir le cerveau MyBrain (outil `nourrir_mybrain`) :\n"
            . "- Seulement quand l'utilisateur le demande explicitement (« ajoute ça au cerveau », « garde-le "
            . "dans MyBrain », « transmets ce compte rendu à MyBrain »). Jamais de ta propre initiative.\n"
            . "- Rédige un texte AUTOPORTANT et fidèle : les faits tels que l'utilisateur les a donnés (qui, "
            . "quoi, quand, source), sans rien inventer ni embellir, et rien de sensible qu'il n'a pas demandé "
            . "à transmettre.\n"
            . "- Une carte d'approbation s'affiche : ne dis JAMAIS que le texte est entré dans le cerveau. Dis "
            . "qu'il attend son approbation, puis la validation dans la revue de MyBrain.";
    }

    /**
     * La carte d'approbation (clés lues par renderDriveConfirmCard, chat.js).
     * PURE. Traduite : c'est de l'interface.
     *
     * @param array{titre: string, texte: string, lien: string, url: string} $args arguments normalisés
     */
    public static function actionPreview(array $args, string $baseName, string $email): array
    {
        $impacts = [
            t("Le texte part dans la file de revue du cerveau « {base} » : rien n'entre dans le wiki sans qu'une personne l'y valide.", ['base' => $baseName]),
            t('Il est transmis à votre nom ({email}) : la question de la revue vous sera adressée si vous avez un compte MyBrain.', ['email' => $email]),
            t("L'analyse prend de quelques secondes à quelques minutes ; le lien de la proposition sera ajouté à la conversation."),
        ];
        if ($args['url'] !== '') {
            $impacts[] = t('MyBrain lira lui-même la page à cette adresse.');
        }
        $apercu = $args['url'] !== '' ? $args['url'] : $args['texte'];
        if (mb_strlen($apercu) > 280) {
            $apercu = mb_substr($apercu, 0, 280) . '…';
        }

        return [
            'action'  => t('Proposer au cerveau MyBrain'),
            'fichier' => $args['titre'] !== '' ? $args['titre'] : $args['url'],
            'url'     => null,
            'impacts' => $impacts,
            'apercu'  => $apercu,
            'icone'   => '/assets/img/mybrain.png',
        ];
    }

    /**
     * Le message ⏳ posé au clic « Approuver », visible au rechargement même si
     * le suivi n'a pas commencé. PURE.
     *
     * @param list<string> $avertissements ceux de MyBrain (« … n'a pas de compte myBrain … »)
     */
    public static function pendingMessage(string $titre, string $baseName, array $avertissements): string
    {
        $txt = '⏳ ' . t("« {titre} » est transmis au cerveau « {base} » : MyBrain l'analyse, le lien de la proposition sera ajouté ici.", [
            'titre' => $titre,
            'base'  => $baseName,
        ]);
        foreach (array_slice($avertissements, 0, 3) as $a) {
            $txt .= "\n- " . $a;
        }
        return $txt;
    }

    /**
     * Le message final ✅ d'un dépôt analysé — null tant qu'il ne l'est pas
     * (en file, en cours) ou s'il a échoué (l'appelant lève). PURE.
     *
     * @param array{statut: string, zone_restreinte: bool, proposition_url: string, source_url: string, pages: list<string>} $suivi
     */
    public static function resultMessage(array $suivi, string $titre, string $baseName): ?string
    {
        if ($suivi['statut'] !== 'fait') {
            return null;
        }
        if ($suivi['zone_restreinte']) {
            // Une clé d'application n'a jamais le droit restreint : MyBrain ne
            // lui dit que cela, et c'est tout ce que MIA peut répéter.
            return '✅ ' . t('« {titre} » est rangé dans la zone RH du cerveau « {base} » : seule sa direction peut le lire et le trancher.', [
                'titre' => $titre,
                'base'  => $baseName,
            ]);
        }
        if ($suivi['proposition_url'] !== '') {
            $n = count($suivi['pages']);
            return '✅ ' . t('« {titre} » est en revue dans le cerveau « {base} » : [trancher la proposition]({url}){pages}.', [
                'titre' => $titre,
                'base'  => $baseName,
                'url'   => $suivi['proposition_url'],
                'pages' => $n > 0 ? ' — ' . I18n::plural($n, '{n} fiche concernée', '{n} fiches concernées') : '',
            ]);
        }
        return '✅ ' . t('Le cerveau « {base} » a lu « {titre} » : rien de neuf à proposer.', ['titre' => $titre, 'base' => $baseName])
            . ($suivi['source_url'] !== '' ? ' [' . t('Page source') . '](' . $suivi['source_url'] . ')' : '');
    }

    /** Le suivi a rendu la main avant la fin de l'analyse : ce n'est pas un échec. PURE. */
    public static function stillRunningMessage(string $titre, string $baseName, string $revueUrl): string
    {
        return '⏳ ' . t('« {titre} » est toujours en analyse dans le cerveau « {base} ». La proposition apparaîtra dans sa [revue]({url}).', [
            'titre' => $titre,
            'base'  => $baseName,
            'url'   => $revueUrl,
        ]);
    }

    // ===================== Appels =====================

    /**
     * Parmi les bases en direct engagées pour ce message, celles que CETTE
     * personne peut nourrir maintenant : « peut nourrir » accordé, clé qui
     * porte le droit « nourrir », connexion ni refusée ni en pause. Aucun
     * appel réseau.
     *
     * @param list<array<string, mixed>> $liveKbs
     * @return list<array<string, mixed>>
     */
    public static function feedableBases(array $liveKbs, array $user): array
    {
        $out = [];
        foreach ($liveKbs as $kb) {
            if (!KnowledgeBase::canFeed($kb, $user)) {
                continue;
            }
            $row = KnowledgeBaseRemote::find((int)$kb['id']);
            if ($row !== null && self::connectionAllowsFeeding($row)) {
                $out[] = $kb;
            }
        }
        return $out;
    }

    /** La connexion permet-elle un dépôt maintenant ? PURE sur la ligne de connexion. */
    public static function connectionAllowsFeeding(array $row): bool
    {
        return in_array(MyBrainClient::RIGHT_FEED, KnowledgeBaseRemote::rights($row), true)
            && (string)($row['status'] ?? '') !== KnowledgeBaseRemote::STATUS_REFUSED
            && !KnowledgeBaseRemote::isPaused($row);
    }

    /**
     * Le dépôt, au clic « Approuver » — jamais pendant le stream. Consigne
     * l'état de la connexion comme un contexte (disjoncteur compris).
     *
     * @param array{titre: string, texte: string, lien: string, url: string} $args arguments normalisés
     * @return array{job: string, pour: ?string, avertissements: list<string>}
     * @throws \RuntimeException message pour l'utilisateur
     */
    public static function deposit(array $kb, array $args, string $pour, ?callable $transport = null): array
    {
        $row = KnowledgeBaseRemote::find((int)$kb['id']);
        if ($row === null) {
            throw new \RuntimeException(t('Connexion MyBrain non configurée — prévenez un administrateur.'));
        }
        if (!in_array(MyBrainClient::RIGHT_FEED, KnowledgeBaseRemote::rights($row), true)) {
            throw new \RuntimeException(t("La clé qui relie MIA à ce cerveau n'a pas le droit « nourrir » — prévenez un administrateur."));
        }
        if ((string)($row['status'] ?? '') === KnowledgeBaseRemote::STATUS_REFUSED) {
            throw new \RuntimeException(t('Connexion MyBrain refusée — à vérifier par un administrateur'));
        }
        if (KnowledgeBaseRemote::isPaused($row)) {
            throw new \RuntimeException(t('MyBrain est injoignable pour le moment — réessayez dans une minute.'));
        }
        $client = KnowledgeBaseRemote::client($row, $transport);
        if ($client === null) {
            throw new \RuntimeException(t('Connexion MyBrain illisible — prévenez un administrateur.'));
        }
        try {
            $depot = $client->nourrir($args + ['pour' => $pour]);
        } catch (MyBrainException $e) {
            KnowledgeBaseRemote::markFailure($row, $e);
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        KnowledgeBaseRemote::markOk($row);
        return $depot;
    }

    /**
     * Où en est un dépôt. Hors disjoncteur : un suivi qui interroge toutes les
     * dix secondes ne doit pas couper le cerveau des conversations.
     *
     * @return array{statut: string, position: ?int, erreur: ?string, zone_restreinte: bool,
     *               proposition_url: string, source_url: string, pages: list<string>}
     * @throws MyBrainException
     * @throws \RuntimeException connexion supprimée
     */
    public static function follow(int $kbId, string $job, ?callable $transport = null): array
    {
        $row    = KnowledgeBaseRemote::find($kbId);
        $client = $row !== null ? KnowledgeBaseRemote::client($row, $transport) : null;
        if ($client === null) {
            throw new \RuntimeException(t('La connexion au cerveau a été supprimée pendant le suivi.'));
        }
        return $client->suivi($job);
    }

    /** L'adresse de la revue du cerveau (lien du message « toujours en analyse »). */
    public static function revueUrl(int $kbId): string
    {
        $row = KnowledgeBaseRemote::find($kbId);
        $url = $row !== null ? MyBrainClient::normalizeBaseUrl((string)($row['base_url'] ?? '')) : null;
        return $url !== null ? $url . '/revue' : '';
    }
}
