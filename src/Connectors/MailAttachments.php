<?php

declare(strict_types=1);

/**
 * Pièces jointes des emails lus par les connecteurs Gmail et Outlook — SOURCE
 * UNIQUE de leur inventaire, de leur désignation et de l'extraction de leur
 * texte.
 *
 * LE PROBLÈME RÉSOLU. `lire_gmail` et `lire_outlook` rendaient le corps du
 * message, et rien de ce qui y était joint : « que dit la facture jointe ? »,
 * « résume le compte rendu en pièce jointe » restaient sans réponse, alors que
 * c'est souvent LÀ qu'est l'information. Désormais la lecture d'un email liste
 * ses pièces jointes, et un outil dédié en lit le texte.
 *
 * Trois règles :
 *   - une pièce jointe se désigne par son NUMÉRO ou son NOM, jamais par
 *     l'identifiant technique du fournisseur : chez Gmail il CHANGE à chaque
 *     lecture du message (et il est long de plusieurs centaines de
 *     caractères), le modèle le recopierait mal. Le service relit le message
 *     et résout l'identifiant du moment ;
 *   - seuls les formats que FileExtractor sait lire sont proposés à la
 *     lecture (`lisible`) ; une image, un ancien .doc ou une archive le disent
 *     au lieu d'échouer en « aucun texte » ;
 *   - le texte extrait est rendu en UTF-8 valide : un CSV en Windows-1252
 *     ferait sinon échouer l'encodage JSON du résultat, donc tout l'outil.
 *
 * Pure, sauf `extractText` (fichier temporaire, supprimé dans un `finally`).
 */
final class MailAttachments
{
    /** Au-delà, la pièce jointe n'est pas téléchargée (même plafond que Drive). */
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** Pièces jointes listées dans le résultat d'une lecture d'email. */
    public const MAX_LISTED = 25;

    /**
     * Formats lisibles → MIME passé à FileExtractor. Les formats « texte »
     * sans branche dédiée (CSV, JSON, XML, iCalendar…) sont lus comme du texte
     * brut, exactement comme le fait déjà la lecture Drive pour le CSV.
     */
    private const READABLE_MIMES = [
        'application/pdf' => 'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text'         => 'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation' => 'application/vnd.oasis.opendocument.presentation',
        'application/epub+zip' => 'application/epub+zip',
        'application/rtf'      => 'application/rtf',
        'text/rtf'             => 'application/rtf',
        'text/html'            => 'text/html',
        'text/markdown'        => 'text/markdown',
        'text/plain'           => 'text/plain',
        'text/csv'             => 'text/plain',
        'text/tab-separated-values' => 'text/plain',
        'text/calendar'        => 'text/plain',
        'text/xml'             => 'text/plain',
        'application/xml'      => 'text/plain',
        'application/json'     => 'text/plain',
    ];

    /**
     * Extension → MIME, pour les pièces jointes annoncées en type générique
     * (`application/octet-stream` est fréquent, surtout chez Outlook).
     */
    private const EXTENSION_MIMES = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'epub' => 'application/epub+zip',
        'rtf'  => 'application/rtf',
        'html' => 'text/html', 'htm' => 'text/html',
        'md'   => 'text/markdown', 'markdown' => 'text/markdown',
        'txt'  => 'text/plain', 'log' => 'text/plain',
        'csv'  => 'text/csv', 'tsv' => 'text/tab-separated-values',
        'ics'  => 'text/calendar',
        'xml'  => 'application/xml',
        'json' => 'application/json',
        'yaml' => 'text/plain', 'yml' => 'text/plain',
    ];

    /** Types que l'on annonce par leur nom plutôt que par leur MIME. */
    private const HUMAN_TYPES = [
        'application/pdf' => 'PDF',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'Word',
        'application/msword'                                                         => 'Word (ancien format .doc)',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'Excel',
        'application/vnd.ms-excel'                                                   => 'Excel (ancien format .xls)',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PowerPoint',
        'application/vnd.ms-powerpoint'                                              => 'PowerPoint (ancien format .ppt)',
        'application/vnd.oasis.opendocument.text'         => 'OpenDocument texte',
        'application/vnd.oasis.opendocument.spreadsheet'  => 'OpenDocument tableur',
        'application/vnd.oasis.opendocument.presentation' => 'OpenDocument présentation',
        'application/epub+zip' => 'EPUB',
        'application/rtf'      => 'RTF',
        'text/html'            => 'HTML',
        'text/markdown'        => 'Markdown',
        'text/plain'           => 'texte',
        'text/csv'             => 'CSV',
        'text/calendar'        => 'invitation (.ics)',
        'application/json'     => 'JSON',
        'application/xml'      => 'XML',
        'application/zip'      => 'archive ZIP',
        'message/rfc822'       => 'email joint',
    ];

    // ===================== Inventaire =====================

    /**
     * Pièces jointes d'un message Gmail (`payload` de `messages.get`,
     * format=full) : toute part qui porte un nom de fichier ET un contenu
     * (identifiant de pièce jointe, ou données en ligne pour les petites).
     *
     * @return list<array{numero:int, nom:string, type:string, taille:int, ref:string, data:string, en_ligne:bool, piece:string}>
     */
    public static function fromGmailPayload(array $payload): array
    {
        $found = [];
        $walk = static function (array $part) use (&$walk, &$found): void {
            $name  = trim((string)($part['filename'] ?? ''));
            $body  = is_array($part['body'] ?? null) ? $part['body'] : [];
            $attId = (string)($body['attachmentId'] ?? '');
            $data  = (string)($body['data'] ?? '');
            if ($name !== '' && ($attId !== '' || $data !== '')) {
                $headers = [];
                foreach ((array)($part['headers'] ?? []) as $h) {
                    if (is_array($h)) {
                        $headers[strtolower((string)($h['name'] ?? ''))] = (string)($h['value'] ?? '');
                    }
                }
                $found[] = [
                    'nom'      => self::cleanName($name),
                    'type'     => strtolower(trim((string)($part['mimeType'] ?? ''))),
                    'taille'   => (int)($body['size'] ?? 0),
                    'ref'      => $attId,
                    'data'     => $attId === '' ? $data : '',
                    // Image collée dans le corps (logo de signature…) : listée,
                    // mais signalée — ce n'est presque jamais « la pièce jointe ».
                    'en_ligne' => str_starts_with(strtolower($headers['content-disposition'] ?? ''), 'inline')
                        && isset($headers['content-id']),
                    'piece'    => 'fichier',
                ];
            }
            foreach ((array)($part['parts'] ?? []) as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($payload);
        return self::numbered($found);
    }

    /**
     * Pièces jointes d'un message Outlook (`GET /messages/{id}/attachments`).
     * Trois natures chez Graph : fichier (`fileAttachment`, lisible), email
     * joint (`itemAttachment`) et lien de partage (`referenceAttachment`) —
     * ces deux derniers n'ont pas d'octets à extraire.
     *
     * @return list<array{numero:int, nom:string, type:string, taille:int, ref:string, data:string, en_ligne:bool, piece:string}>
     */
    public static function fromGraphList(array $value): array
    {
        $found = [];
        foreach ($value as $a) {
            if (!is_array($a) || trim((string)($a['id'] ?? '')) === '') {
                continue;
            }
            $odata = strtolower((string)($a['@odata.type'] ?? ''));
            $piece = match (true) {
                str_contains($odata, 'itemattachment')      => 'email',
                str_contains($odata, 'referenceattachment') => 'lien',
                default                                    => 'fichier',
            };
            $found[] = [
                'nom'      => self::cleanName((string)($a['name'] ?? '')) ?: '(sans nom)',
                'type'     => strtolower(trim((string)($a['contentType'] ?? ''))),
                'taille'   => (int)($a['size'] ?? 0),
                'ref'      => (string)$a['id'],
                'data'     => '',
                'en_ligne' => !empty($a['isInline']),
                'piece'    => $piece,
            ];
        }
        return self::numbered($found);
    }

    /**
     * Forme rendue AU MODÈLE : ni identifiant technique ni octets — il désigne
     * une pièce jointe par son numéro ou son nom.
     *
     * @return list<array{numero:int, nom:string, type:string, taille:string, lisible:bool, en_ligne?:bool}>
     */
    public static function forModel(array $attachments): array
    {
        $out = [];
        foreach (array_slice($attachments, 0, self::MAX_LISTED) as $a) {
            $row = [
                'numero'  => (int)$a['numero'],
                'nom'     => (string)$a['nom'],
                'type'    => self::humanType((string)$a['type'], (string)$a['nom']),
                'taille'  => self::humanSize((int)$a['taille']),
                'lisible' => self::unreadableReason($a) === null,
            ];
            if (!empty($a['en_ligne'])) {
                $row['en_ligne'] = true;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Pièce jointe désignée par le modèle : numéro (« 2 ») ou nom — exact
     * d'abord (casse ignorée), puis partie unique du nom (accents ignorés).
     * Plusieurs candidats → erreur qui les nomme : lire la mauvaise pièce
     * jointe produirait une réponse fausse, avec aplomb.
     *
     * @throws \RuntimeException message FR
     */
    public static function pick(array $attachments, string $wanted): array
    {
        if ($attachments === []) {
            throw new \RuntimeException('Ce message n\'a aucune pièce jointe.');
        }
        $wanted = trim($wanted);
        if ($wanted === '') {
            throw new \RuntimeException('Indique la pièce jointe à lire : son numéro ou son nom (voir `pieces_jointes`).');
        }
        if (preg_match('/^(?:n°|no|#)?\s*(\d{1,3})$/iu', $wanted, $m) === 1) {
            foreach ($attachments as $a) {
                if ((int)$a['numero'] === (int)$m[1]) {
                    return $a;
                }
            }
            throw new \RuntimeException('Aucune pièce jointe n°' . (int)$m[1] . '. ' . self::availableList($attachments));
        }
        foreach ($attachments as $a) {
            if (strcasecmp((string)$a['nom'], $wanted) === 0) {
                return $a;
            }
        }
        $needle = self::fold($wanted);
        $hits   = array_values(array_filter(
            $attachments,
            static fn(array $a): bool => $needle !== '' && str_contains(self::fold((string)$a['nom']), $needle)
        ));
        if (count($hits) === 1) {
            return $hits[0];
        }
        if (count($hits) > 1) {
            throw new \RuntimeException('Plusieurs pièces jointes correspondent à « ' . $wanted . ' » : '
                . implode(', ', array_map(static fn(array $a): string => $a['numero'] . '. ' . $a['nom'], $hits))
                . '. Précise le numéro.');
        }
        throw new \RuntimeException('Aucune pièce jointe ne s\'appelle « ' . $wanted . ' ». ' . self::availableList($attachments));
    }

    /**
     * Pourquoi une pièce jointe ne peut pas être lue, ou null si elle peut
     * l'être. Le message nomme la cause et, quand il y en a une, la suite.
     */
    public static function unreadableReason(array $a): ?string
    {
        $nom = (string)($a['nom'] ?? '');
        if (($a['piece'] ?? 'fichier') === 'email') {
            return '« ' . $nom . ' » est un email joint : son contenu ne se lit pas par cet outil.';
        }
        if (($a['piece'] ?? 'fichier') === 'lien') {
            return '« ' . $nom . ' » est un lien de partage (OneDrive, SharePoint…), pas un fichier joint : ouvre-le depuis Outlook.';
        }
        if ((int)($a['taille'] ?? 0) > self::MAX_BYTES) {
            return '« ' . $nom . ' » est trop volumineuse pour être lue (' . self::humanSize((int)$a['taille'])
                . ', limite ' . self::humanSize(self::MAX_BYTES) . ').';
        }
        $mime = self::canonicalMime((string)($a['type'] ?? ''), $nom);
        if (str_starts_with($mime, 'image/')) {
            return '« ' . $nom . ' » est une image : son contenu ne se lit pas comme du texte.';
        }
        if (!isset(self::READABLE_MIMES[$mime])) {
            return '« ' . $nom . ' » (' . self::humanType((string)($a['type'] ?? ''), $nom) . ') n\'est pas un format lisible. '
                . 'Formats lus : PDF, Word (.docx), Excel (.xlsx), PowerPoint (.pptx), OpenDocument, RTF, HTML, texte, CSV, JSON.';
        }
        return null;
    }

    /**
     * Texte d'une pièce jointe téléchargée, en UTF-8 valide.
     *
     * @throws \RuntimeException message FR
     */
    public static function extractText(string $bytes, string $mime, string $name): string
    {
        if ($bytes === '') {
            throw new \RuntimeException('La pièce jointe « ' . $name . ' » est vide.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('La pièce jointe « ' . $name . ' » est trop volumineuse pour être lue.');
        }
        $canonical = self::canonicalMime($mime, $name);
        $extract   = self::READABLE_MIMES[$canonical] ?? null;
        if ($extract === null) {
            throw new \RuntimeException('Format non lisible : « ' . $name . ' ».');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mia_pj_');
        if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
            throw new \RuntimeException('Écriture temporaire impossible sur le serveur.');
        }
        try {
            $text = FileExtractor::extract($tmp, $extract);
        } finally {
            @unlink($tmp);
        }
        $text = self::toUtf8((string)$text);
        if (trim($text) === '') {
            throw new \RuntimeException('Aucun texte extractible dans la pièce jointe « ' . $name
                . ' » (document scanné ou protégé ?).');
        }
        return $text;
    }

    /** Données base64url (Gmail) → octets. Chaîne vide si illisible. */
    public static function decodeBase64Url(string $data): string
    {
        $decoded = base64_decode(strtr(trim($data), '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    // ===================== Formes =====================

    /**
     * MIME utile d'une pièce jointe : celui annoncé, sauf s'il est générique
     * ou absent — l'extension tranche alors.
     */
    public static function canonicalMime(string $mime, string $name): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $generic = $mime === '' || in_array($mime, [
            'application/octet-stream', 'application/x-download', 'application/download',
            'application/force-download', 'binary/octet-stream', 'application/unknown',
        ], true);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($generic && isset(self::EXTENSION_MIMES[$ext])) {
            return self::EXTENSION_MIMES[$ext];
        }
        // Outlook annonce parfois text/plain pour un .csv ou un .md : l'extension
        // ne fait alors que préciser, la lecture reste du texte.
        if ($mime === 'text/plain' && isset(self::EXTENSION_MIMES[$ext]) && str_starts_with(self::EXTENSION_MIMES[$ext], 'text/')) {
            return self::EXTENSION_MIMES[$ext];
        }
        return $mime;
    }

    /** Type lisible par un humain (« PDF », « Excel », « image »…). */
    public static function humanType(string $mime, string $name): string
    {
        $canonical = self::canonicalMime($mime, $name);
        if (isset(self::HUMAN_TYPES[$canonical])) {
            return self::HUMAN_TYPES[$canonical];
        }
        if (str_starts_with($canonical, 'image/')) {
            return 'image';
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext !== '' ? 'fichier .' . $ext : ($canonical !== '' ? $canonical : 'fichier');
    }

    /** Taille lisible : « 840 o », « 12 Ko », « 3,4 Mo ». */
    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return max(0, $bytes) . ' o';
        }
        if ($bytes < 1024 * 1024) {
            return (string)round($bytes / 1024) . ' Ko';
        }
        return str_replace('.', ',', (string)round($bytes / 1024 / 1024, 1)) . ' Mo';
    }

    /** Numérote dans l'ordre du message (1, 2, 3…). */
    private static function numbered(array $found): array
    {
        $out = [];
        foreach (array_values($found) as $i => $a) {
            $out[] = ['numero' => $i + 1] + $a;
        }
        return $out;
    }

    private static function availableList(array $attachments): string
    {
        return 'Pièces jointes de ce message : '
            . implode(', ', array_map(static fn(array $a): string => $a['numero'] . '. ' . $a['nom'], $attachments))
            . '.';
    }

    /** Nom de fichier d'une ligne, sans caractère de contrôle. */
    private static function cleanName(string $name): string
    {
        return trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
    }

    /**
     * Minuscules sans accents (comparaison des noms). Table explicite plutôt
     * qu'iconv //TRANSLIT, dont la sortie dépend de la locale du process.
     */
    private static function fold(string $s): string
    {
        return strtr(mb_strtolower(trim($s)), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u',
            'ü' => 'u', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', '’' => "'",
        ]);
    }

    /** UTF-8 valide : un texte en Windows-1252 est converti, jamais rendu tel quel. */
    private static function toUtf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        return is_string($converted) ? $converted : mb_scrub($text, 'UTF-8');
    }
}
