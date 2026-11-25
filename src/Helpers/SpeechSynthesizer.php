<?php

declare(strict_types=1);

/**
 * Synthèse vocale des réponses (conversation vocale) — SOURCE UNIQUE.
 *
 * La conversation vocale enchaîne : l'utilisateur parle (session de
 * transcription Realtime, la même que la dictée), le texte part comme un
 * message ordinaire (assistant, bases, connecteurs, quota : tout le
 * pipeline du chat, rien de parallèle), puis la réponse lui est LUE à voix
 * haute — c'est cette dernière étape qui vit ici. L'audio est produit côté
 * serveur (la clé ne quitte jamais le serveur) par la VOIX DE LECTURE que
 * l'administrateur a choisie (carte « Conversation vocale ») :
 *
 *  - `openai`     : API Realtime, `gpt-realtime-2.1-mini` (RealtimeSpeech),
 *                   rendu en WAV ;
 *  - `mistral`    : Voxtral TTS 3 (`voxtral-mini-tts-3-0`), en MP3 ;
 *  - `openai_tts` : `gpt-4o-mini-tts` sur `POST /v1/audio/speech`, la voix
 *                   d'origine, qu'OpenAI ARRÊTE le 6 janvier 2027 (bloc
 *                   `retirement` d'ENGINES) — défaut d'une instance qui n'a
 *                   rien choisi, jusqu'à cette date ; ensuite, sans écriture
 *                   en base, la lecture passe d'elle-même sur `openai`.
 *
 * Quel que soit le moteur, la conversation ÉCOUTE par OpenAI (Realtime).
 *
 * Quatre formes pures, testables sans réseau ni base :
 *   - `speakableText()`   : Markdown → texte qui se lit (plus de balises, de
 *                            liens, de tableaux ni de blocs de code) ;
 *   - `splitForSpeech()`  : texte → parties bornées, coupées aux phrases,
 *                            la PREMIÈRE courte pour que la voix démarre vite ;
 *   - `liveSegment()`     : le passage suivant d'une réponse qui S'ÉCRIT
 *                            ENCORE — la voix démarre dès la première phrase ;
 *   - `audioDurationSeconds()` : durée réelle de l'audio reçu (trames MP3,
 *                            en-tête WAV) — la minute facturée est MESURÉE
 *                            (Voxtral, lui, est facturé au caractère lu).
 *
 * Le réglage d'administration (`voice_chat_enabled`, carte « Conversation
 * vocale » de la page Outils, défaut ACTIF comme la dictée) gouverne le
 * bouton du chat ET les routes qu'il appelle : le serveur reste juge.
 *
 * `issueFrom()` dit si la conversation peut marcher avec l'IA vocale en
 * vigueur : elle écoute par OpenAI, quel que soit le moteur de la dictée
 * (Dictation). Sa réponse pose la pastille rouge du bouton.
 */
final class SpeechSynthesizer
{
    /** Clé Settings de l'interrupteur (carte intégrée de /admin/tools). */
    public const SETTING_ENABLED = 'voice_chat_enabled';

    /** Clé Settings de la voix de lecture choisie ('' = jamais choisie). */
    public const SETTING_ENGINE = 'voice_speech_engine';

    public const ENGINE_OPENAI = 'openai';
    public const ENGINE_MISTRAL = 'mistral';
    public const ENGINE_LEGACY = 'openai_tts';

    /**
     * Voix de lecture — SOURCE UNIQUE (fournisseur, modèle, format rendu au
     * navigateur, libellé de produit, arrêt annoncé). L'ordre est celui de la
     * liste de la carte d'administration.
     *
     * `retirement` : même forme que le bloc du catalogue LLM
     * (LlmCatalog::retirementFrom) — page Deprecations d'OpenAI, entrée du
     * 2026-10-01, relue en verbatim le 2026-11-10 : `tts-1`, `tts-1-hd` et
     * `gpt-4o-mini-tts` coupés le 6 janvier 2027, remplaçant désigné
     * `gpt-realtime-2.1-mini` (= le moteur `openai`).
     */
    public const ENGINES = [
        self::ENGINE_OPENAI => [
            'provider' => 'openai',
            'model'    => RealtimeSpeech::MODEL,
            'format'   => 'wav',
            'label'    => 'OpenAI Realtime (gpt-realtime-2.1-mini)',
        ],
        self::ENGINE_MISTRAL => [
            'provider' => 'mistral',
            'model'    => self::MISTRAL_MODEL,
            'format'   => 'mp3',
            'label'    => 'Mistral Voxtral TTS 3',
        ],
        self::ENGINE_LEGACY => [
            'provider'   => 'openai',
            'model'      => self::MODEL,
            'format'     => 'mp3',
            'label'      => 'OpenAI gpt-4o-mini-tts',
            'retirement' => [
                'date'        => '2027-01-06',
                'replacement' => self::ENGINE_OPENAI,
                'source_url'  => 'https://developers.openai.com/api/docs/deprecations',
            ],
        ],
    ];

    /** Type MIME de chaque format rendu. */
    public const MIME = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav'];

    /** Modèle de la voix d'origine (`openai_tts`) : tarif « estimé à la minute » publié par OpenAI. */
    public const MODEL = 'gpt-4o-mini-tts';

    /** Voix parmi celles de l'API (alloy, ash, ballad, coral, echo, fable, nova, onyx, sage, shimmer, verse). */
    public const VOICE = 'nova';

    public const ENDPOINT = 'https://api.openai.com/v1/audio/speech';

    /**
     * Voxtral TTS 3 (Mistral) : identifiant FIGÉ — aperçu public depuis le
     * 2026-10-20 (fiche du modèle relue le 2026-11-10 : « Input $13 /M
     * Chars », sorties mp3, wav, pcm, flac, opus, mono 24 kHz). Pas de
     * consigne de style (« not available at launch ») : la voix est celle du
     * préréglage, et c'est elle qui fixe la langue.
     */
    public const MISTRAL_MODEL = 'voxtral-mini-tts-3-0';

    public const MISTRAL_ENDPOINT = 'https://api.mistral.ai/v1/audio/speech';

    /**
     * Voix préréglées Voxtral 3 par langue de l'interface (page « Voices » de
     * la doc Mistral) : Marie (français), Sheila (anglais, États-Unis). Une
     * voix a SA langue — le texte s'écrit dans la langue de la voix.
     */
    public const MISTRAL_VOICES = ['fr' => 'voice_592', 'en' => 'voice_478'];

    /** Plafond de l'API par requête (caractères). */
    public const MAX_INPUT_CHARS = 4096;

    /** Première partie courte : la voix démarre pendant que la suite se fabrique. */
    public const FIRST_PART_CHARS = 420;

    /** Parties suivantes : assez longues pour ne pas multiplier les appels. */
    public const PART_CHARS = 1500;

    /**
     * Lecture EN DIRECT (la réponse s'écrit encore, cf. liveSegment) : le
     * premier passage attend au moins une phrase qui compte — « Oui. » seul
     * ne vaut pas un appel de synthèse —, et reste court pour que la voix
     * démarre vite.
     */
    public const LIVE_FIRST_MIN_CHARS = 60;
    public const LIVE_FIRST_MAX_CHARS = 220;

    /** Passages suivants, tant que la réponse s'écrit : jamais quelques mots isolés. */
    public const LIVE_MIN_CHARS = 40;

    /**
     * Fin de phrase dans une ligne de Markdown : ponctuation forte, puis ce
     * qui la referme (parenthèse, guillemet, emphase), suivie d'un blanc — une
     * ponctuation en fin de ligne est couverte par la fin de ligne elle-même.
     */
    private const SENTENCE_END = '/[.!?…]+(?:[)\]"\'’”*_]|[ \x{00A0}\x{202F}]?»)*(?=[ \t])/u';

    /** Délai réseau maximal d'un appel de synthèse (secondes). */
    public const TIMEOUT_SEC = 60;

    /**
     * Cache disque des ANNONCES (courtes phrases fixes : les libellés de la
     * trace d'actions, « Recherche sur Internet »…). Une phrase est
     * synthétisée UNE fois par instance et par langue, puis servie du disque à
     * tout le monde : l'annonce d'une action ne doit ni attendre OpenAI ni
     * coûter un appel à chaque tour. Sous `storage/cache` (volume
     * `storage_cache`), à côté des autres caches d'instance.
     */
    public const CACHE_DIR = '/storage/cache/voice';

    /**
     * Consigne de lecture (gpt-4o-mini-tts suit des `instructions`) : lire, ne
     * rien inventer. Prompt de MÉTHODE, il ne nomme rien à l'écran : reste en
     * français quelle que soit la langue de l'interface.
     */
    public const INSTRUCTIONS = 'Voix naturelle et chaleureuse, débit posé et bien articulé, comme dans une conversation en face à face. Lis le texte tel quel, dans sa langue, sans rien ajouter ni commenter.';

    public static function isEnabled(): bool
    {
        return Settings::get(self::SETTING_ENABLED, '1') === '1';
    }

    // ===================== Voix de lecture =====================

    /** Voix CHOISIE par l'administrateur, ou '' s'il n'en a jamais choisi. */
    public static function chosenEngine(): string
    {
        $value = (string)Settings::get(self::SETTING_ENGINE, '');
        return isset(self::ENGINES[$value]) ? $value : '';
    }

    /**
     * Arrêt annoncé d'une voix (bloc `retirement` d'ENGINES), lu au jour
     * `$today` (par défaut celui de LlmCatalog, que les tests fixent).
     * Null quand aucun arrêt n'est annoncé.
     *
     * @return array{date:string, replacement:string, source_url:string, retired:bool}|null
     */
    public static function retirement(string $engine, ?string $today = null): ?array
    {
        $bloc = self::ENGINES[$engine]['retirement'] ?? null;
        if (!is_array($bloc)) {
            return null;
        }
        return LlmCatalog::retirementFrom($bloc, $today ?? LlmCatalog::today());
    }

    /**
     * Voix EN VIGUEUR, d'après le choix, les clés présentes et le jour. Pure.
     *
     *  - Voxtral choisi et clé Mistral présente → Voxtral ;
     *  - OpenAI Realtime choisi → Realtime ;
     *  - rien de choisi, la voix d'origine choisie, ou Voxtral choisi sans
     *    clé Mistral → la voix d'origine (`openai_tts`) tant qu'OpenAI la
     *    sert, puis Realtime à partir du jour de son arrêt — sans écriture en
     *    base : un choix qui n'existe plus ne casse pas la lecture ;
     *  - sans clé OpenAI, Voxtral n'est jamais pris s'il n'a pas été choisi
     *    (et donc essayé) : null.
     */
    public static function resolveEngine(string $chosen, bool $hasOpenAi, bool $hasMistral, ?string $today = null): ?string
    {
        if ($chosen === self::ENGINE_MISTRAL && $hasMistral) {
            return self::ENGINE_MISTRAL;
        }
        if (!$hasOpenAi) {
            return null;
        }
        if ($chosen === self::ENGINE_OPENAI) {
            return self::ENGINE_OPENAI;
        }
        return (self::retirement(self::ENGINE_LEGACY, $today)['retired'] ?? false)
            ? self::ENGINE_OPENAI
            : self::ENGINE_LEGACY;
    }

    /** Voix en vigueur sur l'instance (lit le réglage et les clés). */
    public static function engine(): ?string
    {
        return self::resolveEngine(
            self::chosenEngine(),
            !empty(ApiKey::getDecrypted('openai')),
            !empty(ApiKey::getDecrypted('mistral'))
        );
    }

    /** Fournisseur dont la clé fait parler une voix. */
    public static function providerOf(string $engine): string
    {
        return (string)(self::ENGINES[$engine]['provider'] ?? 'openai');
    }

    /** Type MIME de l'audio d'une voix. */
    public static function mimeOf(string $engine): string
    {
        return self::MIME[(string)(self::ENGINES[$engine]['format'] ?? 'mp3')] ?? 'audio/mpeg';
    }

    /** Libellé de produit d'une voix (carte d'administration) — jamais traduit. */
    public static function engineLabel(?string $engine): string
    {
        return (string)(self::ENGINES[(string)$engine]['label'] ?? '');
    }

    /** Voix Voxtral de la langue de l'interface (français à défaut). Pure. */
    public static function mistralVoiceFor(string $locale): string
    {
        $code = strtolower(substr(trim($locale), 0, 2));
        return self::MISTRAL_VOICES[$code] ?? self::MISTRAL_VOICES['fr'];
    }

    /** Voxtral en vigueur sans clé OpenAI : la conversation ne peut pas démarrer. */
    public const ISSUE_VOXTRAL_ONLY = 'voxtral_sans_openai';

    /** Compte OpenAI refusé (crédit épuisé, clé refusée) : constaté, pas définitif. */
    public const ISSUE_OPENAI_ACCOUNT = 'compte_openai';

    /**
     * La conversation vocale marchera-t-elle sur cette instance ? Lit le
     * constat en cours sur le compte OpenAI (ProviderIncident) ; le moteur et
     * la présence de la clé viennent de l'appelant, qui les a déjà lus.
     *
     * @return array{code:string, blocking:bool, title:string, message:string}|null
     */
    public static function conversationIssue(?string $engine, bool $hasOpenAi, bool $canFix): ?array
    {
        $incident = ProviderIncident::active()['openai'] ?? null;
        return self::issueFrom($engine, $hasOpenAi, is_array($incident) ? (string)$incident['kind'] : null, $canFix);
    }

    /**
     * Pourquoi la conversation vocale ne marchera pas — null si rien ne s'y
     * oppose. SOURCE UNIQUE de la pastille rouge du bouton onde sonore et de
     * la carte d'administration. Pure.
     *
     *  - Voxtral en vigueur et aucune clé OpenAI : `blocking`. Le micro existe,
     *    l'onde sonore aussi, mais rien ne peut écouter ni lire : le bouton se
     *    montre avec sa raison au lieu de disparaître sans un mot.
     *  - Voxtral en vigueur AVEC une clé OpenAI : null. La conversation marche,
     *    par OpenAI ; une pastille annoncerait une panne qui n'existe pas (la
     *    carte d'administration, elle, dit où part l'audio).
     *  - Aucune IA vocale (ni micro ni clé) : null. Aucune IA vocale n'a été
     *    choisie, il n'y a rien à expliquer à chaque utilisateur : le bouton
     *    reste absent, la carte d'administration et le point à régler le disent.
     *  - Compte OpenAI refusé (`$incident` = quota | auth) : pastille, mais PAS
     *    `blocking`. Le constat peut dater d'avant la recharge : la personne
     *    peut essayer, et une lecture réussie l'éteint (UsageMeter).
     *
     * `$canFix` : la personne peut régler la cause (droit `llms.edit`) — même
     * diagnostic, autre issue.
     *
     * @return array{code:string, blocking:bool, title:string, message:string}|null
     */
    public static function issueFrom(?string $engine, bool $hasOpenAi, ?string $incident, bool $canFix): ?array
    {
        if (!$hasOpenAi) {
            if ($engine !== Dictation::ENGINE_MISTRAL) {
                return null;
            }
            return [
                'code'     => self::ISSUE_VOXTRAL_ONLY,
                'blocking' => true,
                'title'    => t('Conversation vocale indisponible'),
                'message'  => $canFix
                    ? t("La conversation vocale écoute toujours par OpenAI — Voxtral (Mistral) ne sert qu'à la dictée et à la lecture à voix haute —, et aucune clé OpenAI n'est enregistrée : elle ne peut pas démarrer. Renseignez une clé dans Modèles IA › Fournisseurs et modèles, ou masquez-la dans Capacités › Outils.")
                    : t("La conversation vocale vous écoute toujours par OpenAI — Voxtral (Mistral) ne sert qu'à la dictée et à la lecture à voix haute —, et votre organisation n'a pas de compte OpenAI relié à MIA : elle ne peut pas démarrer. Prévenez un administrateur."),
            ];
        }

        $message = match ($incident) {
            'quota' => $canFix
                ? t("La conversation vocale écoute par OpenAI, et le crédit de votre organisation y est épuisé : elle ne marchera pas tant qu'il n'est pas rechargé. Vérifiez le compte dans Modèles IA › Fournisseurs et modèles.")
                : t("La conversation vocale vous écoute par OpenAI, et le crédit de votre organisation y est épuisé : elle ne marchera pas tant qu'il n'est pas rechargé. Prévenez un administrateur."),
            'auth'  => $canFix
                ? t("La conversation vocale écoute par OpenAI, et la clé enregistrée y est refusée : elle ne marchera pas tant qu'elle n'est pas remplacée. Renseignez-en une autre dans Modèles IA › Fournisseurs et modèles.")
                : t("La conversation vocale vous écoute par OpenAI, et la clé d'accès de votre organisation y est refusée : elle ne marchera pas tant qu'elle n'est pas remplacée. Prévenez un administrateur."),
            default => null,
        };
        if ($message === null) {
            return null;
        }
        return [
            'code'     => self::ISSUE_OPENAI_ACCOUNT,
            'blocking' => false,
            'title'    => t('Conversation vocale indisponible'),
            'message'  => $message,
        ];
    }

    // ===================== Formes pures =====================

    /**
     * Markdown d'une réponse → texte lisible à voix haute.
     *
     * Ce qui ne se lit pas disparaît : blocs de code (leur contenu n'a aucun
     * sens à l'oreille — l'écran les garde), images, adresses web. Ce qui se
     * lit perd sa mise en forme : titres, emphases, listes, citations,
     * tableaux (cellules jointes par des virgules). Les marqueurs de citation
     * ChatGPT (zone privée Unicode) sont retirés au cas où un message
     * antérieur à leur nettoyage en porte encore.
     */
    public static function speakableText(string $markdown): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);

        // Blocs de code (``` ou ~~~), fermés ou laissés ouverts en fin de texte.
        $text = (string)preg_replace('/^[ \t]*(`{3,}|~{3,})[^\n]*\n[\s\S]*?(?:^[ \t]*\1[ \t]*$|\z)/mu', "\n", $text);

        // Marqueurs de citation ChatGPT (même règle que ChatController::stripCitationMarkers :
        // le contenu entre U+E200 et U+E201 part avec eux), puis toute la zone privée.
        $text = (string)preg_replace('/\x{E200}.*?\x{E201}/su', '', $text);
        $text = (string)preg_replace('/[\x{E000}-\x{F8FF}]/u', '', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Images : rien à lire. Liens : le texte seul. Adresses nues : retirées.
        $text = (string)preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $text);
        $text = (string)preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $text);
        $text = (string)preg_replace('#<?https?://[^\s<>()]+>?#iu', '', $text);

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                $lines[] = '';
                continue;
            }
            // Filets horizontaux et séparateurs de tableau : bruit.
            if (preg_match('/^(?:[-*_]\s*){3,}$/u', $line) || preg_match('/^\|?(?:\s*:?-{2,}:?\s*\|)+\s*:?-*:?\s*\|?$/u', $line)) {
                continue;
            }
            // Ligne de tableau : cellules jointes par des virgules.
            if (str_starts_with($line, '|')) {
                $cells = array_filter(array_map('trim', explode('|', trim($line, '|'))), static fn(string $c): bool => $c !== '');
                $line  = implode(', ', $cells);
                if ($line === '') {
                    continue;
                }
                $line .= '.';
            }
            // Titres, citations, puces, numéros : le texte seul.
            $line = (string)preg_replace('/^#{1,6}\s+/u', '', $line);
            $line = (string)preg_replace('/^(?:>\s?)+/u', '', $line);
            $line = (string)preg_replace('/^(?:[-*+]|\d{1,3}[.)])\s+/u', '', $line);
            $line = (string)preg_replace('/^\[[ xX]\]\s+/u', '', $line);
            $lines[] = trim($line);
        }
        $text = implode("\n", $lines);

        // Emphases et code en ligne : le contenu seul. Les paires d'abord (gras),
        // puis les simples — bornées à des non-blancs pour ne pas toucher un
        // astérisque ou un souligné isolé (nom_de_variable reste intact).
        $text = (string)preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/su', '$2', $text);
        $text = (string)preg_replace('/(?<![\w\\\\])(\*|_)(?=\S)(.+?)(?<=\S)\1(?!\w)/su', '$2', $text);
        $text = (string)preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/su', '$1', $text);
        $text = (string)preg_replace('/`([^`\n]*)`/u', '$1', $text);
        // Astérisques et soulignés orphelins (listes imbriquées, gras mal fermé).
        $text = (string)preg_replace('/(?<=\s|^)[*_]{1,3}(?=\s|$)/mu', '', $text);
        // Astérisque resté collé à un mot : une emphase que la lecture en direct
        // a coupée entre deux passages, ou mal fermée. Il ne se lit pas.
        $text = (string)preg_replace('/(?<=\s|^)\*+(?=\S)|(?<=\S)\*+(?=\s|$)/mu', '', $text);

        // Blancs : jamais plus d'un saut de ligne, jamais d'espace en double.
        $text = (string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string)preg_replace('/ *\n */u', "\n", $text);
        $text = (string)preg_replace('/\n{2,}/u', "\n", $text);

        return trim($text);
    }

    /** Corps d'une carte lu à voix haute (email, description) : au-delà, « la suite est à l'écran ». */
    public const CARD_BODY_CHARS = 900;

    /**
     * Texte LU pour une carte en attente (« lis-le », conversation vocale).
     *
     * Les mains occupées, on ne peut pas relire un email avant de dire
     * « envoie » : MIA le lit — à qui il part, son objet, son texte. Pure, et
     * bâtie sur l'APERÇU persisté de l'action (`preview_json`, ce que la carte
     * affiche) : jamais un texte reçu du navigateur. Une carte d'approbation
     * ordinaire se lit par son intitulé, ce qu'elle vise et son extrait.
     *
     * Un long message est coupé à une fin de phrase, et MIA le dit : la carte
     * reste la référence, et l'envoi se confirme toujours à part.
     *
     * @param array<string,mixed> $preview aperçu d'une action en attente
     */
    public static function cardText(array $preview): string
    {
        $mail  = is_array($preview['mail'] ?? null) ? $preview['mail'] : null;
        $parts = [];
        $cut   = false;
        if ($mail !== null) {
            $to = self::spokenRecipients((string)($mail['destinataire'] ?? ''));
            $cc = self::spokenRecipients((string)($mail['cc'] ?? ''));
            if (!empty($mail['reponse'])) {
                $parts[] = $to !== '' ? t('Réponse à {destinataire}.', ['destinataire' => $to]) : t('Réponse dans le fil.');
            } elseif ($to !== '') {
                $parts[] = t('Email pour {destinataire}.', ['destinataire' => $to]);
            }
            if ($cc !== '') {
                $parts[] = t('En copie : {cc}.', ['cc' => $cc]);
            }
            $objet = self::speakableText((string)($mail['objet'] ?? ''));
            if ($objet !== '') {
                $parts[] = t('Objet : {objet}.', ['objet' => rtrim($objet, '. ')]);
            }
            $body = self::speakableText((string)($mail['corps'] ?? ''));
            $cut  = !empty($mail['tronque']);
        } else {
            foreach (['action', 'fichier'] as $cle) {
                $ligne = self::speakableText((string)($preview[$cle] ?? ''));
                if ($ligne !== '') {
                    $parts[] = rtrim($ligne, '. ') . '.';
                }
            }
            $body = self::speakableText((string)($preview['apercu'] ?? ''));
        }

        if ($body !== '') {
            $pieces = self::splitForSpeech($body, self::CARD_BODY_CHARS, self::CARD_BODY_CHARS);
            $cut    = $cut || count($pieces) > 1;
            $parts[] = $pieces[0];
        }
        if ($parts === []) {
            return '';
        }
        if ($cut) {
            $parts[] = t('La suite est affichée à l\'écran.');
        }

        return implode(' ', $parts);
    }

    /**
     * Destinataires tels qu'on les DIT : « Jean Dupont <jean@acme.fr> » se lit
     * « Jean Dupont » ; une adresse seule reste une adresse. Quatre au plus —
     * une liste de diffusion récitée ne s'écoute pas.
     */
    public static function spokenRecipients(string $header): string
    {
        $noms = [];
        foreach (preg_split('/[,;]+/', $header) ?: [] as $un) {
            $un  = trim($un);
            $nom = trim((string)preg_replace('/<[^>]*>/', '', $un), " \t\"'");
            $nom = $nom !== '' ? $nom : trim($un, " \t<>\"'");
            if ($nom !== '') {
                $noms[] = $nom;
            }
        }
        $noms  = array_values(array_unique($noms));
        $autres = count($noms) - 4;

        return implode(', ', array_slice($noms, 0, 4))
            . ($autres > 0 ? ' ' . I18n::plural($autres, 'et {n} autre', 'et {n} autres') : '');
    }

    /**
     * Découpe un texte lisible en parties, chacune sous la borne demandée,
     * coupées de préférence en fin de phrase (sinon au dernier blanc, sinon au
     * caractère). La première partie est courte : la lecture commence pendant
     * que la suivante se synthétise.
     *
     * @return list<string>
     */
    public static function splitForSpeech(string $text, int $firstMax = self::FIRST_PART_CHARS, int $max = self::PART_CHARS): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $firstMax = max(1, min($firstMax, self::MAX_INPUT_CHARS));
        $max      = max(1, min($max, self::MAX_INPUT_CHARS));

        // Une réponse à peine plus longue que la première borne part entière :
        // en détacher une queue de quelques mots coûterait un appel de plus
        // pour une prosodie hachée, sans rien gagner sur le démarrage.
        if (mb_strlen($text) <= min($max, (int)($firstMax * 1.5))) {
            return [$text];
        }

        // Unités : phrases (ponctuation forte suivie d'un blanc) ou lignes.
        $units = preg_split('/(?<=[.!?…])\s+|\n+/u', $text) ?: [];
        $units = array_values(array_filter(array_map('trim', $units), static fn(string $u): bool => $u !== ''));

        $parts   = [];
        $current = '';
        foreach ($units as $unit) {
            $limit = $parts === [] ? $firstMax : $max;
            foreach (self::hardSplit($unit, $limit) as $piece) {
                $candidate = $current === '' ? $piece : $current . ' ' . $piece;
                if ($current !== '' && mb_strlen($candidate) > $limit) {
                    $parts[]   = $current;
                    $current   = $piece;
                    $limit     = $max;
                    // Une pièce taillée pour la première borne tient dans la seconde.
                    continue;
                }
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Coupe une unité trop longue pour la borne : au dernier blanc avant la
     * borne, sinon au caractère. Une unité qui tient est rendue telle quelle.
     *
     * @return list<string>
     */
    private static function hardSplit(string $unit, int $limit): array
    {
        $out = [];
        while (mb_strlen($unit) > $limit) {
            $head = mb_substr($unit, 0, $limit);
            $cut  = (int)mb_strrpos($head, ' ');
            if ($cut < (int)($limit / 2)) {
                $cut = $limit; // pas de blanc utile : coupe au caractère
            }
            $out[] = trim(mb_substr($unit, 0, $cut));
            $unit  = trim(mb_substr($unit, $cut));
        }
        if ($unit !== '') {
            $out[] = $unit;
        }
        return $out;
    }

    /**
     * Prochain passage à lire d'une réponse qui S'ÉCRIT ENCORE (lecture en
     * direct de la conversation vocale : la voix démarre dès la première
     * phrase, sans attendre la fin de la réponse).
     *
     * `$raw` est le Markdown reçu jusqu'ici : il ne fait que s'allonger, donc
     * une position (en octets) y reste valable d'un appel à l'autre — `to`,
     * rendu au client, revient comme `$from` au passage suivant. Un passage
     * finit à une frontière SÛRE : fin de ligne, ou fin de phrase hors code en
     * ligne, lien, gras ou parenthèse restés ouverts ; jamais dans un bloc de
     * code (ce qui suit une ouverture de bloc est du code tant que la clôture
     * n'est pas arrivée). La fin du texte n'est une frontière que lorsque la
     * réponse est terminée (`$final`) : avant, la dernière phrase peut encore
     * s'allonger.
     *
     * Premier passage (`$first`) : la PREMIÈRE frontière qui donne au moins
     * LIVE_FIRST_MIN caractères, sous LIVE_FIRST_MAX. Suivants : la DERNIÈRE
     * frontière sous `$max` — tout ce qui est prêt —, jamais moins de
     * LIVE_MIN caractères tant que la réponse s'écrit. Une phrase qui déborde
     * la borne est coupée au dernier blanc. Les blocs de code ne comptent
     * pas : ils ne se lisent pas.
     *
     * Rend null quand rien de complet n'est prêt (attendre la suite — ou fin
     * de lecture si `$final`), sinon `['to' => octet de fin, 'text' => texte
     * lisible]` ; `text` vide = rien de lisible dans le passage (bloc de code,
     * image) : l'appelant le saute.
     *
     * @return array{to:int, text:string}|null
     */
    public static function liveSegment(string $raw, int $from, bool $final, bool $first = false, int $max = self::PART_CHARS): ?array
    {
        $len  = strlen($raw);
        $from = max(0, min($from, $len));
        while ($from < $len && (ord($raw[$from]) & 0xC0) === 0x80) {
            $from++; // jamais au milieu d'un caractère
        }
        if ($from >= $len) {
            return null;
        }
        if ($first) {
            $min = self::LIVE_FIRST_MIN_CHARS;
            $max = self::LIVE_FIRST_MAX_CHARS;
        } else {
            $min = self::LIVE_MIN_CHARS;
            $max = max(self::LIVE_MIN_CHARS, min($max, self::PART_CHARS));
        }

        // Frontières [octet, caractères lisibles depuis $from], dans l'ordre.
        $cuts  = [];
        $count = 0;    // caractères lisibles avant la ligne courante
        $fence = null; // ouverture du bloc de code en cours (``` ou ~~~), null hors bloc
        $pos   = $from;
        while ($pos < $len) {
            $nl       = strpos($raw, "\n", $pos);
            $complete = $nl !== false;
            $end      = $complete ? $nl : $len;
            $line     = substr($raw, $pos, $end - $pos);
            $next     = $complete ? $nl + 1 : $len;

            // Blocs de code : même règle que speakableText (clôture = la même
            // suite de caractères, seule sur sa ligne). Rien ne s'y coupe.
            if ($fence !== null) {
                if ($complete && preg_match('/^[ \t]*' . preg_quote($fence, '/') . '[ \t]*$/', $line)) {
                    $fence  = null;
                    $cuts[] = [$next, $count];
                }
                $pos = $next;
                continue;
            }
            if (preg_match('/^[ \t]*(`{3,}|~{3,})/', $line, $m)) {
                $fence = $m[1];
                $pos   = $next;
                continue;
            }

            // Fins de phrase dans la ligne : seulement là où rien n'est resté
            // ouvert, et jamais après un simple numéro ou une initiale
            // (« 1. », « M. ») — une phrase compte au moins deux lettres.
            $sentenceStart = $pos;
            if (preg_match_all(self::SENTENCE_END, $line, $found, PREG_OFFSET_CAPTURE)) {
                foreach ($found[0] as [$match, $offset]) {
                    $cut  = $pos + (int)$offset + strlen((string)$match);
                    $head = substr($raw, $pos, $cut - $pos);
                    if (!self::inlineBalanced($head)
                        || preg_match_all('/\p{L}/u', substr($raw, $sentenceStart, $cut - $sentenceStart)) < 2) {
                        continue;
                    }
                    $cuts[]        = [$cut, $count + mb_strlen($head)];
                    $sentenceStart = $cut;
                }
            }
            $count += mb_strlen($line) + ($complete ? 1 : 0);
            if ($complete) {
                $cuts[] = [$next, $count];
            }
            $pos = $next;
        }
        if ($final && ($cuts === [] || $cuts[count($cuts) - 1][0] < $len)) {
            $cuts[] = [$len, $count];
        }

        $eligible = array_values(array_filter($cuts, static fn(array $c): bool => $c[1] <= $max));
        $overflow = $count > $max; // la phrase en cours déborde déjà la borne
        $pick     = null;
        if ($first) {
            foreach ($eligible as $c) {
                if ($c[1] >= $min) {
                    $pick = $c;
                    break;
                }
            }
            if ($pick === null && ($final || $overflow) && $eligible !== []) {
                $pick = $eligible[count($eligible) - 1];
            }
        } elseif ($eligible !== []) {
            $last = $eligible[count($eligible) - 1];
            if ($last[1] >= $min || $overflow || ($final && $last[0] === $len)) {
                $pick = $last;
            }
        }
        $to = $pick !== null ? $pick[0] : ($overflow ? self::hardCut($raw, $from, $max) : null);
        if ($to === null || $to <= $from) {
            return null;
        }

        return ['to' => $to, 'text' => self::speakableText(substr($raw, $from, $to - $from))];
    }

    /**
     * Rien d'ouvert dans ce début de ligne : code en ligne, gras, lien,
     * parenthèse. Couper là laisserait des balises orphelines de part et
     * d'autre de la coupure — et une parenthèse lue en deux fois.
     */
    private static function inlineBalanced(string $head): bool
    {
        return substr_count($head, '`') % 2 === 0
            && substr_count($head, '**') % 2 === 0
            && substr_count($head, '[') <= substr_count($head, ']')
            && substr_count($head, '(') <= substr_count($head, ')');
    }

    /**
     * Coupe forcée d'une phrase qui déborde la borne sans la moindre
     * frontière : au dernier blanc sous la borne, sinon au caractère. Jamais
     * dans un bloc de code. Rend l'octet de fin, ou null.
     */
    private static function hardCut(string $raw, int $from, int $max): ?int
    {
        $window = mb_substr(substr($raw, $from), 0, $max);
        if (preg_match('/^[ \t]*(?:`{3,}|~{3,})/m', $window, $m, PREG_OFFSET_CAPTURE)) {
            $window = substr($window, 0, (int)$m[0][1]);
        }
        if ($window === '') {
            return null;
        }
        $blank = max((int)strrpos($window, ' '), (int)strrpos($window, "\n"));
        $cut   = $blank >= (int)(strlen($window) / 2) ? $blank : strlen($window);
        return $from + $cut;
    }

    /**
     * Durée d'un flux MP3, en secondes, par lecture des en-têtes de trames
     * (MPEG-1, MPEG-2 et 2.5, Layer III — ce qu'OpenAI produit). Un tag ID3v2
     * en tête est sauté ; une trame Xing/Info (VBR) n'est pas comptée ; un
     * octet qui n'ouvre pas une trame est ignoré (resynchronisation). Rend 0
     * sur un flux vide ou non reconnu : « non mesuré », jamais une durée
     * inventée.
     */
    public static function mp3DurationSeconds(string $bytes): float
    {
        $len = strlen($bytes);
        $pos = 0;
        if ($len >= 10 && substr($bytes, 0, 3) === 'ID3') {
            $size = ((ord($bytes[6]) & 0x7F) << 21) | ((ord($bytes[7]) & 0x7F) << 14)
                  | ((ord($bytes[8]) & 0x7F) << 7)  |  (ord($bytes[9]) & 0x7F);
            $pos = 10 + $size + ((ord($bytes[5]) & 0x10) ? 10 : 0);
        }

        // kbit/s par index de débit, Layer III : MPEG-1 puis MPEG-2 / 2.5.
        $bitratesV1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
        $bitratesV2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
        // Fréquences d'échantillonnage par version (index 0..2).
        $sampleRates = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];

        $seconds = 0.0;
        $frames  = 0;
        while ($pos + 4 <= $len) {
            $b1 = ord($bytes[$pos]);
            $b2 = ord($bytes[$pos + 1]);
            if ($b1 !== 0xFF || ($b2 & 0xE0) !== 0xE0) {
                $pos++;
                continue;
            }
            $b3      = ord($bytes[$pos + 2]);
            $version = ($b2 >> 3) & 0x03; // 0 = MPEG-2.5, 1 = réservé, 2 = MPEG-2, 3 = MPEG-1
            $layer   = ($b2 >> 1) & 0x03; // 1 = Layer III
            $brIdx   = ($b3 >> 4) & 0x0F;
            $srIdx   = ($b3 >> 2) & 0x03;
            $padding = ($b3 >> 1) & 0x01;
            if ($version === 1 || $layer !== 1 || $brIdx === 0 || $brIdx === 15 || $srIdx === 3) {
                $pos++;
                continue;
            }
            $bitrate    = ($version === 3 ? $bitratesV1 : $bitratesV2)[$brIdx] * 1000;
            $sampleRate = $sampleRates[$version][$srIdx];
            $samples    = $version === 3 ? 1152 : 576;
            $frameLen   = intdiv($samples * $bitrate, 8 * $sampleRate) + $padding;
            if ($frameLen <= 4) {
                $pos++;
                continue;
            }
            $frame = substr($bytes, $pos, $frameLen);
            // Trame d'en-tête VBR (Xing/Info) : des métadonnées, pas du son.
            if ($frames === 0 && (str_contains($frame, 'Xing') || str_contains($frame, 'Info'))) {
                $pos += $frameLen;
                continue;
            }
            $seconds += $samples / $sampleRate;
            $frames++;
            $pos += $frameLen;
        }

        return $seconds;
    }

    /**
     * Durée d'un WAV PCM lue dans ses en-têtes (débit du bloc `fmt `, taille
     * du bloc `data`, bornée à ce qui est réellement là). 0 si ce n'est pas
     * un WAV lisible — jamais une durée inventée. Pure.
     */
    public static function wavDurationSeconds(string $bytes): float
    {
        $len = strlen($bytes);
        if ($len < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            return 0.0;
        }
        $byteRate = 0;
        $pos = 12;
        while ($pos + 8 <= $len) {
            $id   = substr($bytes, $pos, 4);
            $size = unpack('V', substr($bytes, $pos + 4, 4))[1];
            $body = $pos + 8;
            if ($id === 'fmt ' && $body + 12 <= $len) {
                $byteRate = unpack('V', substr($bytes, $body + 8, 4))[1];
            } elseif ($id === 'data') {
                if ($byteRate <= 0) {
                    return 0.0;
                }
                return min($size, $len - $body) / $byteRate;
            }
            $pos = $body + $size + ($size % 2);
        }
        return 0.0;
    }

    /** Durée de l'audio reçu selon son format (`mp3` | `wav`). Pure. */
    public static function audioDurationSeconds(string $bytes, string $format): float
    {
        return $format === 'wav' ? self::wavDurationSeconds($bytes) : self::mp3DurationSeconds($bytes);
    }

    // ===================== Appel =====================

    /**
     * Lit UN passage avec la voix donnée (cf. ENGINES) — point d'entrée des
     * routes de lecture. Rend l'audio, son format et son type MIME, le
     * fournisseur et le modèle (facturation), la durée MESURÉE et le nombre
     * de caractères lus. Lève une SpeechSynthesisException (cause présentée)
     * ou une RuntimeException au message FR, jamais le détail brut du
     * fournisseur.
     *
     * @param callable|null $transport substitut des tests, de la forme attendue par le moteur
     * @return array{audio:string, format:string, mime:string, model:string, provider:string, seconds:float, chars:int}
     */
    public static function speak(string $engine, string $text, string $apiKey, string $locale, ?callable $transport = null): array
    {
        return match ($engine) {
            self::ENGINE_OPENAI  => RealtimeSpeech::synthesize($text, $apiKey, $transport),
            self::ENGINE_MISTRAL => self::synthesizeMistral($text, $apiKey, $locale, $transport),
            self::ENGINE_LEGACY  => self::synthesize($text, $apiKey, $transport),
            default              => throw new \RuntimeException('Voix de lecture inconnue.'),
        };
    }

    /**
     * Essai RÉEL d'une voix avant de la retenir (carte d'administration) :
     * un mot lu, non compté au grand livre — comme les tests de clés de
     * Modèles IA. Null si tout va bien, sinon la cause présentée.
     *
     * @return array{title:string, message:string}|null
     */
    public static function check(string $engine, string $apiKey, string $locale, ?callable $transport = null): ?array
    {
        try {
            self::speak($engine, $locale === 'en' ? 'Hello.' : 'Bonjour.', $apiKey, $locale, $transport);
            return null;
        } catch (SpeechSynthesisException $e) {
            $p = $e->payload();
            return ['title' => (string)($p['title'] ?? ''), 'message' => (string)($p['message'] ?? $e->getMessage())];
        } catch (\RuntimeException $e) {
            return ['title' => '', 'message' => $e->getMessage()];
        }
    }

    /**
     * Voxtral TTS 3 : `POST /v1/audio/speech` de Mistral, réponse JSON dont
     * `audio_data` porte le MP3 en base64. Facturé par Mistral au CARACTÈRE
     * lu (rendu dans `chars`) ; la durée mesurée reste rendue.
     *
     * @param callable|null $transport fn(string $url, array $headers, string $body): array{status:int, body:string}
     * @return array{audio:string, format:string, mime:string, model:string, provider:string, seconds:float, chars:int}
     */
    public static function synthesizeMistral(string $text, string $apiKey, string $locale, ?callable $transport = null): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Rien à lire.');
        }
        $body = json_encode([
            'model'           => self::MISTRAL_MODEL,
            'input'           => $text,
            'voice_id'        => self::mistralVoiceFor($locale),
            'response_format' => 'mp3',
        ], JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Texte impossible à encoder pour la synthèse.');
        }
        $headers  = ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $apiKey];
        $response = $transport !== null
            ? $transport(self::MISTRAL_ENDPOINT, $headers, $body)
            : self::curlPost(self::MISTRAL_ENDPOINT, $headers, $body);

        $status = (int)($response['status'] ?? 0);
        $raw    = (string)($response['body'] ?? '');
        if ($status === 0) {
            throw new SpeechSynthesisException(LlmErrorPresenter::present(['kind' => 'network', 'provider' => 'mistral', 'status' => null]));
        }
        if ($status !== 200) {
            error_log('[speech Voxtral] HTTP ' . $status . ' : ' . substr($raw, 0, 500));
            $cause = LlmErrorPresenter::fromHttp($status, $raw, 'mistral');
            ProviderIncident::note($cause);
            throw new SpeechSynthesisException($cause);
        }
        $json  = json_decode($raw, true);
        $audio = is_array($json) && is_string($json['audio_data'] ?? null) ? base64_decode($json['audio_data'], true) : false;
        if (!is_string($audio) || $audio === '') {
            error_log('[speech Voxtral] réponse sans audio : ' . substr($raw, 0, 200));
            throw new \RuntimeException('Le service de synthèse vocale n\'a renvoyé aucun audio.');
        }

        return [
            'audio'    => $audio,
            'format'   => 'mp3',
            'mime'     => self::MIME['mp3'],
            'model'    => self::MISTRAL_MODEL,
            'provider' => 'mistral',
            'seconds'  => self::mp3DurationSeconds($audio),
            'chars'    => mb_strlen($text),
        ];
    }

    /**
     * Voix d'origine (`gpt-4o-mini-tts`, arrêtée le 6 janvier 2027) :
     * synthétise UNE partie (≤ MAX_INPUT_CHARS). Rend l'audio MP3, le modèle
     * et la durée mesurée. Lève une RuntimeException au message FR sans fuite
     * (le corps brut du fournisseur ne part qu'aux logs).
     *
     * @param callable|null $transport fn(string $url, array $headers, string $body): array{status:int, body:string} —
     *                                 substitut des tests ; par défaut cURL.
     * @return array{audio:string, format:string, mime:string, model:string, provider:string, seconds:float, chars:int}
     */
    public static function synthesize(string $text, string $apiKey, ?callable $transport = null): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Rien à lire.');
        }
        if (mb_strlen($text) > self::MAX_INPUT_CHARS) {
            throw new \RuntimeException('Texte trop long pour une synthèse (' . self::MAX_INPUT_CHARS . ' caractères maximum).');
        }
        $body = json_encode([
            'model'           => self::MODEL,
            'input'           => $text,
            'voice'           => self::VOICE,
            'response_format' => 'mp3',
            'instructions'    => self::INSTRUCTIONS,
        ], JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Texte impossible à encoder pour la synthèse.');
        }

        $headers  = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
        $response = $transport !== null
            ? $transport(self::ENDPOINT, $headers, $body)
            : self::curlPost(self::ENDPOINT, $headers, $body);

        $status = (int)($response['status'] ?? 0);
        $audio  = (string)($response['body'] ?? '');
        if ($status === 0) {
            throw new SpeechSynthesisException(LlmErrorPresenter::present(['kind' => 'network', 'provider' => 'openai', 'status' => null]));
        }
        if ($status !== 200) {
            error_log('[speech] OpenAI HTTP ' . $status . ' : ' . substr($audio, 0, 500));
            // Même lecture que pour un message : un compte sans crédit
            // (429 `insufficient_quota`) n'est pas une « limite de débit », et
            // le détail brut du fournisseur ne quitte pas les journaux.
            $cause = LlmErrorPresenter::fromHttp($status, $audio, 'openai');
            ProviderIncident::note($cause);
            throw new SpeechSynthesisException($cause);
        }
        if ($audio === '') {
            throw new \RuntimeException('Le service de synthèse vocale n\'a renvoyé aucun audio.');
        }

        return [
            'audio'    => $audio,
            'format'   => 'mp3',
            'mime'     => self::MIME['mp3'],
            'model'    => self::MODEL,
            'provider' => 'openai',
            'seconds'  => self::mp3DurationSeconds($audio),
            'chars'    => mb_strlen($text),
        ];
    }

    // ===================== Annonces mises en cache =====================

    /**
     * Clé de cache d'une annonce : tout ce qui change la voix produite — la
     * voix de lecture (son modèle, sa voix, sa consigne), la langue, le texte.
     * Pure.
     */
    public static function cueCacheKey(string $engine, string $text, string $locale): string
    {
        $voice = match ($engine) {
            self::ENGINE_OPENAI  => RealtimeSpeech::MODEL . "\n" . RealtimeSpeech::VOICE . "\n" . RealtimeSpeech::INSTRUCTIONS,
            self::ENGINE_MISTRAL => self::MISTRAL_MODEL . "\n" . self::mistralVoiceFor($locale),
            default              => self::MODEL . "\n" . self::VOICE . "\n" . self::INSTRUCTIONS,
        };
        return sha1(implode("\n", [$engine, $voice, $locale, trim($text)]));
    }

    public static function cueCachePath(string $engine, string $text, string $locale, ?string $dir = null): string
    {
        $format = (string)(self::ENGINES[$engine]['format'] ?? 'mp3');
        return rtrim($dir ?? (ROOT . self::CACHE_DIR), '/') . '/' . self::cueCacheKey($engine, $text, $locale) . '.' . $format;
    }

    /** Audio d'une annonce déjà synthétisée par cette voix, ou null. Ne lève jamais. */
    public static function readCachedCue(string $engine, string $text, string $locale, ?string $dir = null): ?string
    {
        $path = self::cueCachePath($engine, $text, $locale, $dir);
        if (!is_file($path)) {
            return null;
        }
        $audio = @file_get_contents($path);
        return is_string($audio) && $audio !== '' ? $audio : null;
    }

    /**
     * Synthétise une annonce et la met en cache (écriture ATOMIQUE : fichier
     * temporaire puis `rename`, pour qu'un lecteur concurrent ne voie jamais
     * un fichier à moitié écrit). Rend `cached = true` quand l'audio vient du
     * disque — rien n'a été consommé, rien à facturer. Un cache impossible à
     * écrire (droits, disque plein) n'empêche pas l'annonce : elle est
     * simplement resynthétisée la prochaine fois. Changer de voix de lecture
     * change la clé : les annonces de l'ancienne voix ne sont plus servies.
     *
     * @return array{audio:string, format:string, mime:string, model:string, provider:string, seconds:float, chars:int, cached:bool}
     */
    public static function synthesizeCached(string $engine, string $text, string $apiKey, string $locale, ?callable $transport = null, ?string $dir = null): array
    {
        $hit = self::readCachedCue($engine, $text, $locale, $dir);
        if ($hit !== null) {
            $format = (string)(self::ENGINES[$engine]['format'] ?? 'mp3');
            return [
                'audio'    => $hit,
                'format'   => $format,
                'mime'     => self::mimeOf($engine),
                'model'    => (string)(self::ENGINES[$engine]['model'] ?? ''),
                'provider' => self::providerOf($engine),
                'seconds'  => self::audioDurationSeconds($hit, $format),
                'chars'    => mb_strlen(trim($text)),
                'cached'   => true,
            ];
        }

        $result = self::speak($engine, $text, $apiKey, $locale, $transport);

        $path   = self::cueCachePath($engine, $text, $locale, $dir);
        $folder = dirname($path);
        if (!is_dir($folder)) {
            @mkdir($folder, 0775, true);
        }
        if (is_dir($folder) && is_writable($folder)) {
            $tmp = @tempnam($folder, 'cue-');
            if ($tmp !== false && @file_put_contents($tmp, $result['audio']) !== false) {
                @chmod($tmp, 0664);
                if (!@rename($tmp, $path)) {
                    @unlink($tmp);
                }
            } elseif ($tmp !== false) {
                @unlink($tmp);
            }
        }

        $result['cached'] = false;
        return $result;
    }

    /** @return array{status:int, body:string} */
    private static function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        if ($raw === false) {
            error_log('[speech] cURL : ' . $err);
            return ['status' => 0, 'body' => ''];
        }
        return ['status' => $status, 'body' => (string)$raw];
    }
}
