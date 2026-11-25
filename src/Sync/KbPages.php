<?php

declare(strict_types=1);

/**
 * Espaces → Bases de connaissances (piste D) : ce que la LISTE, la page de
 * CONSULTATION et la FICHE (création comme modification) montrent toutes les
 * trois — pictogrammes, visibilité d'une base, tuile (logo de la source ou
 * livre / tableau), nature d'un fichier. Pur, sans base de données : une seule
 * règle, donc trois écrans qui ne se contredisent jamais.
 *
 * Trois visibilités (KnowledgeBase::visibility) :
 *   - Privée   (cadenas) : son créateur seul, administrateurs compris exclus ;
 *   - Commune  (groupe)  : son créateur, les administrateurs, et les groupes
 *                          ou personnes à qui elle est partagée ;
 *   - Publique (globe)   : toute l'organisation la voit et la coche dans le
 *                          chat ; seuls son créateur et les administrateurs la
 *                          modifient.
 */
final class KbPages
{
    /** Tracés 24×24 au trait (currentColor). */
    private const PATHS = [
        'book'    => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'table'   => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 10h18M3 15h18M9.5 10v10"/>',
        'lock'    => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'group'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'globe'   => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'arrow'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'back'    => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'chat'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'pencil'  => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'id'      => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h4M7 13h10M7 17h6"/>',
        'doc'     => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>',
        'robot'   => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4M9 14h.01M15 14h.01"/>',
        'cog'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'map'     => '<path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2z"/><path d="M9 4v14M15 6v14"/>',
        'check'   => '<path d="m5 12 5 5L20 7"/>',
        'upload'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'columns' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/>',
        'retry'   => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        'ban'     => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
        'trash'   => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
        'chev'    => '<path d="m9 18 6-6-6-6"/>',
        'alert'   => '<path d="M12 3 2 21h20z"/><path d="M12 10v4M12 17h.01"/>',
    ];

    /** Natures de fichier affichées en pastille (« PDF », « DOC »…). */
    private const FILE_KINDS = [
        'pdf'  => 'pdf',
        'doc'  => 'doc', 'docx' => 'doc', 'odt' => 'doc', 'rtf' => 'doc',
        'xls'  => 'xls', 'xlsx' => 'xls', 'ods' => 'xls', 'csv' => 'xls',
        'ppt'  => 'ppt', 'pptx' => 'ppt', 'odp' => 'ppt',
        'png'  => 'img', 'jpg' => 'img', 'jpeg' => 'img', 'gif' => 'img', 'webp' => 'img',
    ];

    /** Un pictogramme, prêt à poser (aria-hidden). Nom inconnu : ''. */
    public static function icon(string $name, string $class = 'kbx-ic'): string
    {
        if (!isset(self::PATHS[$name])) {
            return '';
        }
        return '<svg class="' . htmlspecialchars($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . self::PATHS[$name] . '</svg>';
    }

    /**
     * Un instant, prêt à poser : `<time datetime="…Z" data-local>` que
     * kb-doc.js (MiaKbDoc.localTimes) réécrit dans le fuseau du NAVIGATEUR —
     * le serveur tourne en UTC et ne connaît pas celui de la personne. Sans
     * script, l'heure reste lisible, dite en UTC. '' sans instant.
     */
    public static function timeTag(?int $ts): string
    {
        if ($ts === null || $ts <= 0) {
            return '';
        }
        $fallback = Format::dateFr(gmdate('Y-m-d', $ts)) . ' ' . gmdate('H:i', $ts) . ' UTC';
        return '<time datetime="' . gmdate('Y-m-d\\TH:i:s\\Z', $ts) . '" data-local>' . htmlspecialchars($fallback) . '</time>';
    }

    /**
     * Une phrase traduite qui porte un instant : la phrase est échappée, puis
     * `{date}` y devient la balise <time> (jamais l'inverse : te() échapperait
     * la balise).
     */
    public static function withTime(string $phrase, ?int $ts): string
    {
        $tag = self::timeTag($ts);
        return $tag === '' ? '' : str_replace('{date}', $tag, htmlspecialchars($phrase));
    }

    /** Pictogramme d'une visibilité : cadenas, groupe ou globe. */
    public static function visibilityIcon(string $visibility): string
    {
        return self::icon(match ($visibility) {
            KnowledgeBase::VIS_PRIVATE => 'lock',
            KnowledgeBase::VIS_PUBLIC  => 'globe',
            default                    => 'group',
        }, '');
    }

    /**
     * Ce qu'on dit d'une visibilité : son nom et une phrase.
     * `$grants` = nombre de groupes et de personnes à qui une base commune
     * est partagée — sans aucun, elle est « vous et les administrateurs ».
     *
     * @return array{key:string, label:string, hint:string}
     */
    public static function visibility(string $visibility, int $grants = 0): array
    {
        return match ($visibility) {
            KnowledgeBase::VIS_PRIVATE => [
                'key'   => $visibility,
                'label' => t('Privée'),
                'hint'  => t('Vous seul : ni les administrateurs ni personne d\'autre.'),
            ],
            KnowledgeBase::VIS_PUBLIC => [
                'key'   => $visibility,
                'label' => t('Publique'),
                'hint'  => t('Toute l\'organisation la voit et la coche dans le chat. Seuls son créateur et les administrateurs la modifient.'),
            ],
            default => [
                'key'   => KnowledgeBase::VIS_COMMON,
                'label' => t('Commune'),
                'hint'  => $grants > 0
                    ? I18n::plural($grants, 'Vous, les administrateurs et {n} groupe ou personne.', 'Vous, les administrateurs et {n} groupes ou personnes.')
                    : t('Vous et les administrateurs. Partagez-la à des groupes ou à des personnes.'),
            ],
        };
    }

    /**
     * Tuile d'une base : le logo de sa source (dossier synchronisé, cerveau
     * MyBrain), sinon un livre ; une table, son pictogramme. `$big` = l'en-tête.
     */
    public static function tile(array $kb, bool $big = false): string
    {
        $isTable  = KnowledgeBase::isTable($kb);
        $provider = KnowledgeBase::isRemote($kb) ? 'mybrain' : (string)($kb['sync_provider'] ?? '');
        $inner    = $isTable ? self::icon('table', '') : self::icon('book', '');
        if (!$isTable && isset(KbDiagram::SOURCES[$provider])) {
            $inner = '<img src="' . htmlspecialchars(KbDiagram::SOURCES[$provider]['logo']) . '" alt="">';
        }
        return '<span class="kbx-tile' . ($isTable ? ' is-table' : '') . ($big ? ' is-big' : '') . '" aria-hidden="true">' . $inner . '</span>';
    }

    /** Nature d'un fichier pour sa pastille (pdf, doc, xls, ppt, img, txt). */
    public static function fileKind(string $fileName): string
    {
        $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        return self::FILE_KINDS[$ext] ?? 'txt';
    }

    /** Extension → nature, pour un script qui pose la même pastille (fiche d'un assistant). */
    public static function fileKinds(): array
    {
        return self::FILE_KINDS;
    }

    /** Libellé court de la pastille d'un fichier (« PDF », « XLSX »… quatre lettres au plus). */
    public static function fileBadge(string $fileName): string
    {
        $ext = strtoupper((string)pathinfo($fileName, PATHINFO_EXTENSION));
        return $ext === '' ? 'TXT' : mb_substr($ext, 0, 4);
    }

    /**
     * Ce que la liste dit d'une base, sous son nom : nature et volume, puis
     * dernière utilisation. Pur.
     *
     * @param array $kb   ligne `knowledge_bases` (forManager : doc_count, table_rows, last_used_age…)
     */
    public static function listMeta(array $kb): string
    {
        $parts = [];
        if (KnowledgeBase::isTable($kb)) {
            $parts[] = I18n::plural((int)($kb['table_rows'] ?? 0), '{n} ligne', '{n} lignes');
            $parts[] = I18n::plural((int)($kb['table_columns'] ?? 0), '{n} colonne', '{n} colonnes');
        } elseif (KnowledgeBase::isRemote($kb)) {
            $parts[] = t('Cerveau MyBrain, en direct');
        } else {
            $parts[] = I18n::plural((int)($kb['doc_count'] ?? 0), '{n} document', '{n} documents');
            $provider = (string)($kb['sync_provider'] ?? '');
            if ($provider !== '') {
                $parts[] = KbDiagram::sourceLabel($provider);
            }
        }
        $age = $kb['last_used_age'] ?? null;
        $parts[] = $age === null || $age === ''
            ? t('jamais utilisée')
            : t('utilisée {quand}', ['quand' => KbStatus::ago((int)$age)]);
        return implode(' · ', $parts);
    }
}
