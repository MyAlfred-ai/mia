<?php

declare(strict_types=1);

/**
 * Synthèse vocale des réponses (conversation vocale) — SOURCE UNIQUE.
 *
 * La conversation vocale enchaîne : l'utilisateur parle (session de
 * transcription Realtime, la même que la dictée), le texte part comme un
 * message ordinaire (assistant, bases, connecteurs, quota : tout le
 * pipeline du chat, rien de parallèle), puis la réponse lui est LUE à voix
 * haute — c'est cette dernière étape qui vit ici. L'audio est produit par
 * `POST /v1/audio/speech` d'OpenAI, côté serveur (la clé ne quitte jamais le
 * serveur), et renvoyé au navigateur en MP3.
 *
 * Quatre formes pures, testables sans réseau ni base :
 *   - `speakableText()`   : Markdown → texte qui se lit (plus de balises, de
 *                            liens, de tableaux ni de blocs de code) ;
 *   - `splitForSpeech()`  : texte → parties bornées, coupées aux phrases,
 *                            la PREMIÈRE courte pour que la voix démarre vite ;
 *   - `liveSegment()`     : le passage suivant d'une réponse qui S'ÉCRIT
 *                            ENCORE — la voix démarre dès la première phrase ;
 *   - `mp3DurationSeconds()` : durée réelle de l'audio reçu, lue dans les
 *                            en-têtes de trames — c'est la grandeur facturée
 *                            (OpenAI publie un tarif « à la minute »).
 *
 * Le réglage d'administration (`voice_chat_enabled`, carte « Conversation
 * vocale » de la page Outils, défaut ACTIF comme la dictée) gouverne le
 * bouton du chat ET les routes qu'il appelle : le serveur reste juge.
 */
final class SpeechSynthesizer
{
    /** Clé Settings de l'interrupteur (carte intégrée de /admin/tools). */
    public const SETTING_ENABLED = 'voice_chat_enabled';

    /** Modèle de synthèse : tarif « estimé à la minute » publié par OpenAI. */
    public const MODEL = 'gpt-4o-mini-tts';

    /** Voix parmi celles de l'API (alloy, ash, ballad, coral, echo, fable, nova, onyx, sage, shimmer, verse). */
    public const VOICE = 'nova';

    public const ENDPOINT = 'https://api.openai.com/v1/audio/speech';

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

    // ===================== Appel OpenAI =====================

    /**
     * Synthétise UNE partie (≤ MAX_INPUT_CHARS). Rend l'audio MP3, le modèle
     * et la durée mesurée. Lève une RuntimeException au message FR sans fuite
     * (le corps brut du fournisseur ne part qu'aux logs).
     *
     * @param callable|null $transport fn(string $url, array $headers, string $body): array{status:int, body:string} —
     *                                 substitut des tests ; par défaut cURL.
     * @return array{audio:string, model:string, seconds:float, chars:int}
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
            'audio'   => $audio,
            'model'   => self::MODEL,
            'seconds' => self::mp3DurationSeconds($audio),
            'chars'   => mb_strlen($text),
        ];
    }

    // ===================== Annonces mises en cache =====================

    /**
     * Clé de cache d'une annonce : tout ce qui change la voix produite —
     * modèle, voix, consigne de lecture, langue, texte. Pure.
     */
    public static function cueCacheKey(string $text, string $locale): string
    {
        return sha1(implode("\n", [self::MODEL, self::VOICE, self::INSTRUCTIONS, $locale, trim($text)]));
    }

    public static function cueCachePath(string $text, string $locale, ?string $dir = null): string
    {
        return rtrim($dir ?? (ROOT . self::CACHE_DIR), '/') . '/' . self::cueCacheKey($text, $locale) . '.mp3';
    }

    /** Audio d'une annonce déjà synthétisée, ou null. Ne lève jamais. */
    public static function readCachedCue(string $text, string $locale, ?string $dir = null): ?string
    {
        $path = self::cueCachePath($text, $locale, $dir);
        if (!is_file($path)) {
            return null;
        }
        $audio = @file_get_contents($path);
        return is_string($audio) && $audio !== '' ? $audio : null;
    }

    /**
     * Synthétise une annonce et la met en cache (écriture ATOMIQUE : fichier
     * temporaire puis `rename`, pour qu'un lecteur concurrent ne voie jamais
     * un MP3 à moitié écrit). Rend `cached = true` quand l'audio vient du
     * disque — rien n'a été consommé, rien à facturer. Un cache impossible à
     * écrire (droits, disque plein) n'empêche pas l'annonce : elle est
     * simplement resynthétisée la prochaine fois.
     *
     * @return array{audio:string, model:string, seconds:float, chars:int, cached:bool}
     */
    public static function synthesizeCached(string $text, string $apiKey, string $locale, ?callable $transport = null, ?string $dir = null): array
    {
        $hit = self::readCachedCue($text, $locale, $dir);
        if ($hit !== null) {
            return [
                'audio'   => $hit,
                'model'   => self::MODEL,
                'seconds' => self::mp3DurationSeconds($hit),
                'chars'   => mb_strlen(trim($text)),
                'cached'  => true,
            ];
        }

        $result = self::synthesize($text, $apiKey, $transport);

        $path   = self::cueCachePath($text, $locale, $dir);
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
