/**
 * Fichiers livrés dans une conversation — cartes, aperçus, téléchargement.
 *
 * LE CONTRAT (côté serveur : src/Files/FileDelivery.php). Un fichier est un
 * bloc de code dont la ligne d'ouverture porte un nom :
 *
 *     ```csv fichier="budget-2026.xlsx"
 *     Poste;Montant
 *     ```
 *
 * Ce module en fait une CARTE (nom, type, aperçu, bouton « Télécharger ») et
 * demande au serveur de fabriquer le vrai fichier — un classeur depuis du CSV,
 * un document Word depuis du Markdown. Rien n'est stocké : le contenu affiché
 * EST la source, envoyée à `POST /files/build` au moment du clic.
 *
 * Trois niveaux, du plus riche au plus simple :
 *   1. bloc NOMMÉ            → carte de fichier ;
 *   2. bloc de code ordinaire → bouton « Télécharger » dans son en-tête, à
 *      côté de « Copier » (un modèle qui ignore la convention, ou une
 *      conversation d'avant, reste téléchargeable) ;
 *   3. image                 → bouton de téléchargement sur l'image.
 *
 * Aucune liste de formats ici : libellés, familles et formats de rechange
 * viennent de `window.FILE_FORMATS`, rendu par FileFormats::clientCatalog().
 *
 * Chargé AVANT chat.js (le moteur Markdown doit connaître les cartes dès le
 * premier rendu). Les fonctions de chat.js dont il se sert (renderMarkdown,
 * triggerBlobDownload, showToast) ne sont appelées qu'à l'exécution.
 *
 * La partie PURE (lecture de la déclaration, analyse d'un tableau) est
 * exécutée sous node par les tests : `parseTable` doit rester d'accord avec
 * TabularData (PHP), qui fabrique le classeur — cf. tests/Fixtures.
 */
(function (root) {
    'use strict';

    // ------------------------------------------------------------------
    // 0. Catalogue
    // ------------------------------------------------------------------

    const CATALOG = Object.assign({
        endpoint: '/files/build',
        formats: {},
        languages: {},
        alternatives: {},
        unsupported: {},
        attributes: ['fichier', 'file', 'filename', 'nom', 'name', 'title', 'titre'],
        generic: { label: 'Fichier {type}', stem: 'fichier' },
        maxBytes: 2000000,
    }, root.FILE_FORMATS || {});

    /** Attributs qui ne désignent un fichier QUE s'ils portent une extension. */
    const LOOSE_ATTRIBUTES = ['title', 'titre'];

    /** Aperçu : au-delà, le reste n'est que dans le fichier téléchargé. */
    const PREVIEW_ROWS = 50;
    const PREVIEW_COLS = 30;
    const PREVIEW_CHARS = 20000;

    /** Délai au-delà duquel on cesse d'attendre le serveur (ms). */
    const BUILD_TIMEOUT_MS = 90000;

    // ------------------------------------------------------------------
    // 1. Déclaration d'un fichier (ligne d'ouverture du bloc) — PUR
    // ------------------------------------------------------------------

    /** « nom.ext » plausible : un radical, un point, une extension qui porte une lettre. */
    function looksLikeFilename(value) {
        return /[^\s.\/\\]\.(?=[a-z0-9]*[a-z])[a-z0-9]{1,10}$/i.test(String(value || ''));
    }

    /** Extension d'un nom ('' si aucune). Même règle que FileFormats::normalizeExtension. */
    function extensionOf(name) {
        const m = String(name || '').match(/\.((?=[a-z0-9]*[a-z])[a-z0-9]{1,10})$/i);
        const dot = String(name || '').lastIndexOf('.');
        return m && dot > 0 ? m[1].toLowerCase() : '';
    }

    /** Nom nettoyé : dernier segment, sans caractère interdit par Windows ou macOS. */
    function cleanName(name) {
        let out = String(name || '').replace(/\\/g, '/');
        out = out.slice(out.lastIndexOf('/') + 1);
        out = out.replace(/[\u0000-\u001F\u007F]+/g, ' ').replace(/[<>:"|?*]+/g, '-').replace(/\s+/g, ' ');
        out = out.replace(/^[ .\-_]+|[ .\-_]+$/g, '');
        return out.slice(0, 120);
    }

    /** Radical d'un nom (sans son extension). */
    function stemOf(name) {
        const clean = cleanName(name);
        const ext = extensionOf(clean);
        const stem = ext ? clean.slice(0, clean.length - ext.length - 1) : clean;
        return stem.replace(/^[ .\-_]+|[ .\-_]+$/g, '');
    }

    /**
     * Ligne d'ouverture d'un bloc de code → { lang, name }.
     *
     * Volontairement plus tolérant que ce que le prompt enseigne : outre
     * `fichier="…"`, les modèles écrivent `file=…`, `title="…"`,
     * `csv:nom.csv` ou `csv nom.csv`, habitudes prises ailleurs. `name` vide =
     * bloc de code ordinaire.
     */
    function parseInfo(info) {
        let text = String(info || '').trim();
        if (!text) return { lang: '', name: '' };

        let name = '';
        const attr = new RegExp(
            '(?:^|[\\s{,;])(' + CATALOG.attributes.join('|') + ')\\s*[=:]\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s}]+))',
            'i'
        );
        const found = text.match(attr);
        if (found) {
            const value = found[2] !== undefined ? found[2] : (found[3] !== undefined ? found[3] : (found[4] || ''));
            const loose = LOOSE_ATTRIBUTES.indexOf(found[1].toLowerCase()) !== -1;
            if (!loose || looksLikeFilename(value)) {
                name = value;
                text = (text.slice(0, found.index) + ' ' + text.slice(found.index + found[0].length)).trim();
            }
        }

        const words = text.split(/\s+/).filter(Boolean);
        let lang = words[0] || '';

        // Forme « langage:nom.ext ».
        const colon = lang.indexOf(':');
        if (colon > 0) {
            const candidate = lang.slice(colon + 1);
            if (!name && looksLikeFilename(candidate)) name = candidate;
            if (looksLikeFilename(candidate) || candidate === '') lang = lang.slice(0, colon);
        }
        // Forme « langage nom.ext ».
        if (!name && words.length > 1 && looksLikeFilename(words[1])) name = words[1];
        // Le « langage » est lui-même un nom de fichier (```budget.csv).
        if (!name && looksLikeFilename(lang) && !CATALOG.languages[lang.toLowerCase()]) {
            name = lang;
            lang = '';
        }

        lang = lang.toLowerCase().replace(/^[{.]+/, '').replace(/[},]+$/, '');
        return { lang: lang, name: cleanName(name) };
    }

    /** Fiche d'un format ; une extension hors catalogue est un fichier texte recopié. */
    function formatOf(ext) {
        if (CATALOG.formats[ext]) return CATALOG.formats[ext];
        const type = String(ext || '').toUpperCase();
        return {
            label: String(CATALOG.generic.label).replace('{type}', type),
            menu: type + ' (.' + ext + ')',
            family: 'code',
            tone: 'code',
            stem: CATALOG.generic.stem,
        };
    }

    /**
     * Fichier déclaré → tout ce que la carte doit savoir : nom final, format,
     * famille, formats proposés, et la raison d'un refus s'il y en a un.
     */
    function describe(name, lang) {
        const language = String(lang || '').toLowerCase();
        const ext = extensionOf(cleanName(name))
            || CATALOG.languages[language]
            || (CATALOG.formats[language] ? language : '')
            || 'txt';
        const format = formatOf(ext);
        const refusal = CATALOG.unsupported[ext] || null;
        const stem = stemOf(name) || format.stem;

        let targets;
        if (refusal) {
            // Format qu'on ne sait pas fabriquer : on propose ses issues, et
            // toujours le texte, qui s'enregistre dans tous les cas.
            targets = (refusal.use || []).concat(['txt']);
        } else {
            const family = CATALOG.alternatives[format.family] || [];
            targets = [ext].concat(family);
        }
        targets = targets.filter(function (x, i, all) { return all.indexOf(x) === i; });

        return {
            name: stem + '.' + ext,
            stem: stem,
            ext: ext,
            lang: language,
            format: format,
            refusal: refusal ? refusal.why : '',
            targets: targets,
            preview: previewKind(ext, format.family, language, !!refusal),
        };
    }

    /** Nature de l'aperçu : table | markdown | text | code. */
    function previewKind(ext, family, lang, refused) {
        const plain = ['text', 'txt', 'plaintext', 'plain'].indexOf(lang) !== -1;
        if (refused) {
            if (lang === 'csv' || lang === 'tsv') return 'table';
            return plain ? 'text' : (lang === 'markdown' || lang === 'md' || lang === '' ? 'markdown' : 'code');
        }
        if (family === 'table') return 'table';
        // Une présentation s'écrit en Markdown comme un document : même aperçu.
        if (family === 'document' || family === 'presentation') {
            if (plain) return 'text';
            return lang === 'html' ? 'code' : 'markdown';
        }
        return family === 'text' ? 'text' : 'code';
    }

    /**
     * Extension sous laquelle enregistrer le contenu TEL QUEL (issue de secours
     * sans serveur) : la syntaxe réelle de la source, pas le format demandé —
     * le texte d'un classeur est du CSV, celui d'un document du Markdown.
     */
    function rawExtension(file) {
        if (file.preview === 'table') return file.ext === 'tsv' ? 'tsv' : 'csv';
        if (file.preview === 'markdown') return 'md';
        if (file.refusal || (CATALOG.formats[file.ext] && ['xlsx', 'docx', 'pdf', 'rtf', 'pptx'].indexOf(file.ext) !== -1)) {
            return 'txt';
        }
        return file.ext;
    }

    /**
     * La carte propose-t-elle Gamma (« Version soignée › Par Gamma ») ? Une PRÉSENTATION écrite
     * en Markdown (un .pptx, ou un .key/.ppt/.odp refusé au profit du .pptx) :
     * c'est ce que le serveur sait découper en cartes (MarkdownDeck::sourceCards).
     * Que le compte ait accès à Gamma, c'est window.FILE_GAMMA qui le dit.
     */
    function offersGamma(file) {
        return file.preview === 'markdown'
            && (file.format.family === 'presentation' || file.targets.indexOf('pptx') !== -1);
    }

    /**
     * Genre de fichier que la « Version soignée » d'un modèle produirait depuis cette
     * carte : 'pptx' pour une présentation, 'xlsx' pour un tableau (classeur,
     * CSV), '' sinon. Même règle que NativeDeck::kindFor côté serveur.
     */
    function nativeKind(file) {
        if (offersGamma(file)) return 'pptx';
        if (file.preview === 'table' && file.targets.indexOf('xlsx') !== -1) return 'xlsx';
        return '';
    }

    // ------------------------------------------------------------------
    // 2. Tableau → lignes (aperçu) — PUR, miroir de TabularData (PHP)
    // ------------------------------------------------------------------

    const DELIMITERS = ['\t', ';', ',', '|'];
    const SNIFF_RECORDS = 60;

    function trimBlanks(value) {
        return value.replace(/^[ \t]+|[ \t]+$/g, '');
    }

    /** Vide au sens du `trim()` de PHP (espace, tabulation, sauts de ligne, NUL). */
    function isBlank(value) {
        return /^[ \t\n\r\v\0]*$/.test(value);
    }

    function normalizeTable(source) {
        let text = String(source == null ? '' : source);
        if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
        return text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\0/g, '');
    }

    /** Formule non citée dont un appel de fonction (ou une chaîne) reste ouvert. */
    function formulaIsOpen(cell) {
        const text = cell.replace(/^[ \t]+/, '');
        if (text === '' || text.charAt(0) !== '=') return false;
        let depth = 0;
        let inString = false;
        for (let i = 1; i < text.length; i++) {
            const ch = text.charAt(i);
            if (ch === '"') inString = !inString;
            else if (!inString) {
                if (ch === '(') depth++;
                else if (ch === ')') depth--;
            }
        }
        return depth > 0 || inString;
    }

    /** Texte délimité → enregistrements (même automate que TabularData::parseDelimited). */
    function parseDelimited(text, delimiter) {
        const rows = [];
        let row = [];
        let cell = '';
        let quoted = false;
        let inside = false;

        const endCell = function () {
            row.push(quoted ? cell : trimBlanks(cell));
            cell = '';
            quoted = false;
        };
        const endRow = function () {
            endCell();
            if (!(row.length === 1 && row[0] === '')) rows.push(row);
            row = [];
        };

        for (let i = 0; i < text.length; i++) {
            const ch = text.charAt(i);

            if (inside) {
                if (ch === '"') {
                    if (text.charAt(i + 1) === '"') { cell += '"'; i++; }
                    else inside = false;
                } else {
                    cell += ch;
                }
                continue;
            }

            if (ch === '"' && !quoted && trimBlanks(cell) === '') {
                cell = '';
                quoted = true;
                inside = true;
            } else if (quoted && (ch === ' ' || ch === '\t') && ch !== delimiter) {
                continue;
            } else if (delimiter !== '' && ch === delimiter) {
                if (!quoted && formulaIsOpen(cell)) cell += ch;
                else endCell();
            } else if (ch === '\n') {
                endRow();
            } else {
                cell += ch;
            }
        }
        if (cell !== '' || quoted || row.length > 0) endRow();

        return rows;
    }

    function firstRecordsText(text) {
        let offset = 0;
        for (let i = 0; i < SNIFF_RECORDS; i++) {
            const next = text.indexOf('\n', offset);
            if (next === -1) return text;
            offset = next + 1;
            if (offset >= text.length) return text;
        }
        return text.slice(0, offset);
    }

    /** Séparateur le plus régulier ('' = une seule colonne). Cf. TabularData::detectDelimiter. */
    function detectDelimiter(text) {
        const sample = firstRecordsText(text);
        let best = '';
        let bestScore = 0;
        let bestCols = 0;

        DELIMITERS.forEach(function (candidate) {
            if (sample.indexOf(candidate) === -1) return;
            const records = parseDelimited(sample, candidate);
            if (!records.length) return;

            const frequency = {};
            records.forEach(function (record) {
                frequency[record.length] = (frequency[record.length] || 0) + 1;
            });
            let mode = 0;
            let hits = 0;
            Object.keys(frequency).forEach(function (key) {
                const n = Number(key);
                const count = frequency[key];
                if (count > hits || (count === hits && n > mode)) { mode = n; hits = count; }
            });
            if (mode < 2) return;

            const score = hits / records.length;
            if (score > bestScore + 1e-9 || (Math.abs(score - bestScore) <= 1e-9 && mode > bestCols)) {
                best = candidate;
                bestScore = score;
                bestCols = mode;
            }
        });

        return best;
    }

    /** Découpe une ligne de table Markdown sur les barres non échappées. */
    function splitMarkdownRow(line) {
        let text = line.trim();
        if (text.charAt(0) === '|') text = text.slice(1);
        if (text.slice(-1) === '|' && text.slice(-2) !== '\\|') text = text.slice(0, -1);

        const cells = [];
        let current = '';
        for (let i = 0; i < text.length; i++) {
            const ch = text.charAt(i);
            if (ch === '|' && text.charAt(i - 1) !== '\\') {
                cells.push(current);
                current = '';
            } else {
                current += ch;
            }
        }
        cells.push(current);

        return cells.map(function (cell) {
            let out = cell.replace(/\\\|/g, '|').trim();
            out = out.replace(/<br\s*\/?>/gi, '\n');
            out = out.replace(/(\*\*|__)(\S(?:.*?\S)?)\1/g, '$2');
            out = out.replace(/(^|[^\p{L}\p{N}_*])\*([^\s*](?:[^*]*?[^\s*])?)\*(?![\p{L}\p{N}_*])/gu, '$1$2');
            out = out.replace(/`([^`]+)`/g, '$1');
            return out;
        });
    }

    function parseMarkdownTable(text) {
        const lines = text.split('\n').filter(function (line) { return line.trim() !== ''; });
        if (lines.length < 2 || lines[0].indexOf('|') === -1) return null;
        if (!/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/.test(lines[1]) || lines[1].indexOf('-') === -1) return null;

        const rows = [splitMarkdownRow(lines[0])];
        for (let i = 2; i < lines.length; i++) {
            if (lines[i].indexOf('|') === -1) break;
            rows.push(splitMarkdownRow(lines[i]));
        }
        return rows;
    }

    /** Même nombre de cellules partout ; colonnes de fin entièrement vides retirées. */
    function rectangular(rows) {
        if (!rows.length) return [];
        let width = 0;
        rows.forEach(function (row) { width = Math.max(width, row.length); });

        while (width > 1) {
            const empty = rows.every(function (row) {
                return row[width - 1] === undefined || isBlank(row[width - 1]);
            });
            if (!empty) break;
            width--;
        }

        return rows.map(function (row) {
            const out = row.slice(0, width);
            while (out.length < width) out.push('');
            return out;
        });
    }

    /** Tableau écrit par le modèle → { rows, delimiter, markdown }. */
    function parseTable(source) {
        const text = normalizeTable(source);
        if (text.trim() === '') return { rows: [], delimiter: ';', markdown: false };

        const markdown = parseMarkdownTable(text);
        if (markdown) return { rows: rectangular(markdown), delimiter: ';', markdown: true };

        const delimiter = detectDelimiter(text);
        return { rows: rectangular(parseDelimited(text, delimiter)), delimiter: delimiter, markdown: false };
    }

    /**
     * Rééquilibre les délimiteurs d'un DOCUMENT déclaré qui contient lui-même
     * des blocs de code.
     *
     * Un compte rendu ou un README livré en Markdown porte souvent un exemple
     * de code. Délimité par trois accents graves comme le fichier qui le
     * contient, cet exemple le referme : le moteur Markdown s'arrête au premier
     * ``` nu, la carte ne contient que le début du document et la suite
     * s'étale dans la réponse. Le prompt demande quatre accents graves dans ce
     * cas ; les modèles l'oublient.
     *
     * Règle de lecture, à l'intérieur d'un document déclaré seulement : une
     * ligne de délimiteur AVEC un langage ouvre un bloc intérieur, un
     * délimiteur nu referme le dernier bloc ouvert — et le fichier quand il
     * n'en reste plus. Le délimiteur du fichier est alors allongé d'un accent
     * grave de plus que le plus long délimiteur intérieur. Un bloc intérieur
     * SANS langage reste ambigu (rien ne distingue son ouverture d'une
     * fermeture) : il n'est pas deviné.
     *
     * Appelé avant tout rendu (chat.js, renderMarkdown). Pendant l'écriture
     * de la réponse, la fermeture du fichier n'est pas encore arrivée : seule
     * l'ouverture est allongée, ce qui suffit à garder la suite dans la carte.
     */
    function balanceFences(text) {
        if (typeof text !== 'string' || text.indexOf('```') === -1) return text;

        const FENCE = /^([ \t]{0,3})(`{3,})[ \t]*([^`]*?)[ \t]*\r?$/;
        const lines = text.split('\n');
        let changed = false;

        for (let i = 0; i < lines.length; i++) {
            const open = lines[i].match(FENCE);
            if (!open) continue;

            const length = open[2].length;
            const info = parseInfo(open[3]);
            const nestable = !!info.name && describe(info.name, info.lang).preview === 'markdown';

            let depth = 0;
            let inner = 0;
            let close = -1;
            for (let j = i + 1; j < lines.length; j++) {
                const fence = lines[j].match(FENCE);
                if (!fence) continue;
                if (fence[2].length < length) {
                    // Délimiteur plus court : du contenu, quoi qu'il arrive.
                    inner = Math.max(inner, fence[2].length);
                    continue;
                }
                const bare = fence[3] === '';
                if (!bare) {
                    if (nestable) { depth++; inner = Math.max(inner, fence[2].length); }
                    continue;
                }
                if (nestable && depth > 0) {
                    depth--;
                    inner = Math.max(inner, fence[2].length);
                    continue;
                }
                close = j;
                break;
            }

            if (nestable && inner >= length) {
                const fence = new Array(inner + 2).join('`');
                lines[i] = open[1] + fence + lines[i].slice(open[1].length + length);
                if (close !== -1) lines[close] = lines[close].replace(/`{3,}/, fence);
                changed = true;
            }
            if (close === -1) break; // bloc encore ouvert : tout le reste lui appartient
            i = close;
        }

        return changed ? lines.join('\n') : text;
    }

    /**
     * Réponse coupée pendant l'écriture d'un fichier : la note d'interruption
     * (« *[Réponse interrompue par l'utilisateur]* ») a été ajoutée à la fin du
     * texte, donc DANS le bloc resté ouvert. Elle n'est pas une ligne du
     * fichier : on la sépare, sans quoi elle partirait dans le classeur.
     */
    function splitInterruption(text) {
        const source = String(text || '');
        const found = source.match(/\n[ \t]*\n[ \t]*\*\[([^\]\n]+)\]\*[ \t]*\n*$/);
        return found
            ? { source: source.slice(0, found.index), note: found[1] }
            : { source: source, note: '' };
    }

    /** Le bloc a-t-il été refermé ? (pendant l'écriture, il ne l'est pas encore) */
    function isClosedFence(raw) {
        const text = String(raw || '');
        const open = text.match(/^[ \t]{0,3}(`{3,}|~{3,})/);
        if (!open) return true; // bloc indenté : pas de délimiteur à attendre
        const fence = open[1];
        const close = text.match(/\n[ \t]{0,3}(`{3,}|~{3,})[ \t]*\n*$/);
        return !!close && close[1].charAt(0) === fence.charAt(0) && close[1].length >= fence.length;
    }

    const api = {
        parseInfo: parseInfo,
        describe: describe,
        parseTable: parseTable,
        extensionOf: extensionOf,
        cleanName: cleanName,
        stemOf: stemOf,
        rawExtension: rawExtension,
        offersGamma: offersGamma,
        nativeKind: nativeKind,
        isClosedFence: isClosedFence,
        balanceFences: balanceFences,
        splitInterruption: splitInterruption,
    };

    // Sous node (tests) : seule la partie pure existe.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (typeof document === 'undefined') {
        root.MiaFiles = api;
        return;
    }

    // ------------------------------------------------------------------
    // 3. Rendu
    // ------------------------------------------------------------------

    const ICON_DOWNLOAD = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
    const ICON_COPY = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
    const ICON_CHECK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    const ICON_CHEVRON = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function menuLabel(ext) {
        return formatOf(ext).menu;
    }

    /**
     * « au modèle de MyAlfred » : une présentation PowerPoint est remplie dans
     * le modèle de l'entreprise quand l'administrateur en a déposé un
     * (window.FILE_DECK_TEMPLATE = nom de l'entreprise, null sans modèle).
     * Sans cette mention, rien ne disait quel habillage le fichier aurait.
     */
    function deckTemplateNote(file) {
        if (file.refusal || file.ext !== 'pptx' || typeof root.FILE_DECK_TEMPLATE !== 'string') return '';
        const name = root.FILE_DECK_TEMPLATE.trim();

        return ' · ' + (name !== '' ? t('au modèle de {name}', { name: name }) : t('au modèle de l’entreprise'));
    }

    const ICON_SPARK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>';

    /**
     * Modèle de la conversation (sélecteur du composer) : son nom et s'il
     * sait composer un fichier PowerPoint lui-même (window.LLM_INFO,
     * drapeau `native_pptx` du catalogue). Le serveur re-résout le modèle au
     * clic — ceci ne sert qu'à l'affichage.
     */
    function currentLlm() {
        const field = typeof document !== 'undefined' ? document.getElementById('llmSelect') : null;
        const slug = field ? String(field.value || '') : '';
        const info = (root.LLM_INFO || {})[slug] || null;

        return { slug: slug, label: info && info.label ? String(info.label) : '', ok: !!(info && info.native_pptx) };
    }

    /** Le compte dispose-t-il d'un modèle capable ? (window.FILE_NATIVE, sinon le modèle de la conversation) */
    function nativeAvailable() {
        return root.FILE_NATIVE === true || currentLlm().ok;
    }

    /** Raison du bouton grisé : aucun modèle du compte ne sait fabriquer le fichier. */
    function noModelReason() {
        return t('Aucun modèle de votre organisation ne sait réaliser ce fichier : il faut un modèle Claude ou GPT récent.');
    }

    /** Modèle d'une fenêtre préparée (le choisi d'office, sinon celui demandé). */
    function planModel(plan, slug) {
        if (!plan || !Array.isArray(plan.models)) return null;
        const wanted = slug || plan.selected || '';
        for (let i = 0; i < plan.models.length; i++) {
            if (plan.models[i].slug === wanted) return plan.models[i];
        }
        return null;
    }

    /** Info-bulle du bouton : ce qu'il fait, puis — fenêtre préparée — par quel modèle, en combien de temps, pour combien. */
    function polishTitle(kind, gamma, plan) {
        if (!gamma && !nativeAvailable()) return noModelReason();
        let title = gamma ? t('Une mise en page soignée, par un modèle ou par Gamma')
            : kind === 'xlsx' ? t('Le modèle compose le classeur lui-même : formules, mise en forme, graphique')
            : t('Le modèle compose la présentation lui-même, avec sa propre mise en page');
        const chosen = planModel(plan);
        if (chosen) {
            title += ' — ' + t('{model} · 1 à 3 min', { model: chosen.label }) + (chosen.estimate ? ' · ' + chosen.estimate : '');
        }
        return title;
    }

    /**
     * « Version soignée » : UN bouton pour faire composer le fichier par un
     * modèle (présentation ou classeur) — ou, pour une présentation, par
     * Gamma quand un outil Gamma est ouvert au compte : un petit menu propose
     * alors les deux. Le modèle n'est pas forcément celui de la conversation :
     * le serveur choisit (NativeDeck::pickModel) et la fenêtre de confirmation
     * le dit. Toujours présent sur une présentation ou un classeur — grisé,
     * avec la raison, quand rien ne peut le faire (jamais masqué : une absence
     * ne s'explique pas).
     */
    function polishButtonHtml(file) {
        const kind = nativeKind(file);
        if (kind === '') return '';
        const gamma = root.FILE_GAMMA === true && offersGamma(file);
        const ok = gamma || nativeAvailable();
        const label = t('Version soignée');

        return '<div class="file-polish-wrap">'
            + '<button type="button" class="file-polish' + (ok ? '' : ' is-unavailable') + '" data-kind="' + esc(kind) + '"'
            + (gamma ? ' data-gamma="1" aria-haspopup="menu"' : ' aria-haspopup="dialog"') + ' aria-expanded="false"'
            + ' aria-disabled="' + (ok ? 'false' : 'true') + '" title="' + esc(polishTitle(kind, gamma, null)) + '" aria-label="' + esc(label) + '">'
            + ICON_SPARK + '<span class="file-polish-label">' + esc(label) + '</span></button>'
            + '</div>';
    }

    const ICON_EDIT = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';

    /**
     * « Modifier les slides » : la présentation de MIA s'ouvre dans l'éditeur
     * du volet (déplacer, réécrire, illustrer, exporter) — ce que faisait
     * l'ancien outil Présentation du menu « + ».
     */
    function editDeckButtonHtml() {
        const label = t('Modifier les slides');

        return '<button type="button" class="file-edit-deck" title="' + esc(t('Ouvrir la présentation dans l’éditeur : déplacer, réécrire, ajouter des illustrations'))
            + '" aria-label="' + esc(label) + '">' + ICON_EDIT + '<span class="file-edit-deck-label">' + esc(label) + '</span></button>';
    }

    /**
     * Le modèle a changé dans le composer : les boutons au repos suivent, et
     * les fenêtres déjà préparées sont oubliées (le modèle de la conversation,
     * donc le choix d'office, a pu changer).
     */
    function refreshNative() {
        document.querySelectorAll('.file-polish').forEach(function (button) {
            const card = button.closest('.file-card');
            if (card) card.__plan = null;
            if (button.classList.contains('is-busy')) return;
            const gamma = button.dataset.gamma === '1';
            const ok = gamma || nativeAvailable();
            button.classList.toggle('is-unavailable', !ok);
            button.setAttribute('aria-disabled', ok ? 'false' : 'true');
            button.title = polishTitle(button.dataset.kind || 'pptx', gamma, null);
        });
    }

    /** Menu « Autres formats » (masqué) : une entrée par format fabricable. */
    function menuHtml(targets, current) {
        return '<div class="file-menu" role="menu" hidden>'
            + targets.map(function (ext) {
                return '<button type="button" class="file-menu-item' + (ext === current ? ' is-current' : '')
                    + '" role="menuitem" data-ext="' + esc(ext) + '">' + esc(menuLabel(ext)) + '</button>';
            }).join('')
            + '</div>';
    }

    /**
     * Carte d'un fichier, telle que le moteur Markdown la pose dans la réponse.
     *
     * Rendue en CHAÎNE parce qu'elle est recalculée à chaque fragment reçu
     * pendant l'écriture de la réponse : elle ne porte donc aucun état. Ce qui
     * coûte ou dépend de l'écran (aperçu, compteurs) est ajouté une seule
     * fois, réponse terminée, par hydrate().
     */
    function cardHtml(file, source, closed) {
        const first = file.refusal ? file.targets[0] : file.ext;
        const lines = source === '' ? 0 : source.split('\n').length;

        let meta;
        if (!closed) {
            meta = '<span class="file-card-pulse" aria-hidden="true"></span>'
                + esc(t('Création en cours…'))
                + (lines > 0 ? ' · ' + esc(tn(lines, '{n} ligne', '{n} lignes')) : '');
        } else {
            meta = esc((file.refusal || file.format.label) + deckTemplateNote(file));
        }

        let actions = '';
        if (closed) {
            const label = file.refusal
                ? t('Télécharger en {format}', { format: menuLabel(first) })
                : t('Télécharger');
            actions = '<div class="file-card-actions">'
                + (offersGamma(file) ? editDeckButtonHtml() : '')
                + polishButtonHtml(file)
                + '<button type="button" class="file-card-icon file-card-copy" title="' + esc(t('Copier le contenu'))
                + '" aria-label="' + esc(t('Copier le contenu')) + '">' + ICON_COPY + '</button>'
                + '<div class="file-split">'
                + '<button type="button" class="file-download" data-ext="' + esc(first) + '">' + ICON_DOWNLOAD
                + '<span class="file-download-label">' + esc(label) + '</span></button>'
                + (file.targets.length > 1
                    ? '<button type="button" class="file-more" aria-haspopup="menu" aria-expanded="false" title="'
                        + esc(t('Autres formats')) + '" aria-label="' + esc(t('Autres formats')) + '">' + ICON_CHEVRON + '</button>'
                        + menuHtml(file.targets, first)
                    : '')
                + '</div></div>';
        }

        return '<div class="file-card' + (file.refusal ? ' is-refused' : '') + '"'
            + ' data-name="' + esc(file.name) + '" data-lang="' + esc(file.lang) + '"'
            + ' data-ext="' + esc(file.ext) + '" data-tone="' + esc(file.format.tone) + '"'
            + ' data-closed="' + (closed ? '1' : '0') + '">'
            + '<div class="file-card-head">'
            + '<span class="file-card-badge" aria-hidden="true">' + esc(file.ext.toUpperCase().slice(0, 5)) + '</span>'
            + '<div class="file-card-info">'
            + '<span class="file-card-name" title="' + esc(file.name) + '">' + esc(file.name) + '</span>'
            + '<span class="file-card-meta">' + meta + '</span>'
            + '</div>'
            + actions
            + '</div>'
            + '<pre class="file-card-source" hidden><code>' + esc(source) + '</code></pre>'
            + '</div>\n';
    }

    /**
     * Bouton « Télécharger » de l'en-tête d'un bloc de code ORDINAIRE (sans
     * nom). '' si le langage n'a pas de format associé : chat.js n'ajoute alors
     * rien — un bloc `diff` ou un extrait sans langage se copie, c'est tout.
     */
    function codeDownloadHtml(lang) {
        const language = String(lang || '').toLowerCase();
        if (!CATALOG.languages[language]) return '';
        return '<button type="button" class="btn-download-code" data-lang="' + esc(language) + '" title="'
            + esc(t('Télécharger ce contenu dans un fichier')) + '">' + ICON_DOWNLOAD + esc(t('Télécharger')) + '</button>';
    }

    // Le moteur Markdown appelle ce rendu pour CHAQUE bloc de code ; `false`
    // lui rend la main (bloc ordinaire). Neutralisé pendant le rendu d'un
    // aperçu : un bloc nommé DANS un document n'est pas un second fichier.
    let previewDepth = 0;
    if (root.marked && typeof root.marked.use === 'function') {
        root.marked.use({
            renderer: {
                code: function (token) {
                    if (previewDepth > 0 || !token || typeof token !== 'object') return false;
                    const info = parseInfo(token.lang || '');
                    if (!info.name) return false;

                    const closed = isClosedFence(token.raw);
                    const parts = closed ? { source: String(token.text || ''), note: '' } : splitInterruption(token.text);
                    return cardHtml(describe(info.name, info.lang), parts.source, closed)
                        + (parts.note ? '<p><em>[' + esc(parts.note) + ']</em></p>\n' : '');
                },
            },
        });
    }

    // ------------------------------------------------------------------
    // 4. Finition d'une réponse terminée : compteurs, aperçus, images
    // ------------------------------------------------------------------

    function readCard(card) {
        const code = card.querySelector('.file-card-source code');
        const file = describe(card.dataset.name || '', card.dataset.lang || '');
        file.source = code ? code.textContent : '';
        return file;
    }

    function formatSize(bytes) {
        const lang = (root.I18N && root.I18N.lang) || 'fr';
        if (bytes >= 1000000) {
            return t('{n} Mo', { n: (bytes / 1000000).toLocaleString(lang, { maximumFractionDigits: 1 }) });
        }
        if (bytes < 1000) return tn(bytes, '{n} octet', '{n} octets');
        return t('{n} Ko', { n: Math.round(bytes / 1000).toLocaleString(lang) });
    }

    function byteLength(text) {
        try { return new Blob([text]).size; } catch (_e) { return text.length; }
    }

    /** Aperçu d'un tableau : les premières lignes, en grille. */
    function tablePreview(file) {
        const table = parseTable(file.source);
        const rows = table.rows;
        if (!rows.length) return null;

        const el = document.createElement('table');
        const shown = rows.slice(0, PREVIEW_ROWS);
        shown.forEach(function (row, r) {
            const tr = document.createElement('tr');
            row.slice(0, PREVIEW_COLS).forEach(function (cell) {
                const td = document.createElement(r === 0 && rows.length > 1 ? 'th' : 'td');
                td.textContent = cell;
                tr.appendChild(td);
            });
            el.appendChild(tr);
        });

        const cols = rows[0].length;
        const note = (rows.length > PREVIEW_ROWS || cols > PREVIEW_COLS)
            ? t('Aperçu partiel : le fichier téléchargé contient tout le tableau.')
            : '';
        return {
            node: el,
            meta: tn(rows.length, '{n} ligne', '{n} lignes') + ' × ' + tn(cols, '{n} colonne', '{n} colonnes'),
            note: note,
            long: rows.length > 7,
        };
    }

    /** Aperçu d'un document : le Markdown rendu, comme dans la conversation. */
    function documentPreview(file) {
        const source = file.source.slice(0, PREVIEW_CHARS);
        const box = document.createElement('div');
        previewDepth++;
        try {
            box.innerHTML = typeof root.renderMarkdown === 'function'
                ? root.renderMarkdown(source, { withCodeActions: false })
                : esc(source);
        } finally {
            previewDepth--;
        }
        const words = file.source.replace(/[#*_`>|~\-=\[\]()]/g, ' ').split(/\s+/).filter(Boolean).length;
        return {
            node: box,
            meta: tn(words, '{n} mot', '{n} mots'),
            note: file.source.length > PREVIEW_CHARS
                ? t('Aperçu partiel : le fichier téléchargé contient tout le document.')
                : '',
            long: file.source.length > 700 || file.source.split('\n').length > 12,
        };
    }

    /** Aperçu d'un texte ou d'un code : tel quel. */
    function textPreview(file, mono) {
        const box = document.createElement('div');
        box.className = mono ? 'file-preview-mono' : 'file-preview-plain';
        box.textContent = file.source.slice(0, PREVIEW_CHARS);
        const lines = file.source === '' ? 0 : file.source.replace(/\n+$/, '').split('\n').length;
        return {
            node: box,
            meta: tn(lines, '{n} ligne', '{n} lignes') + ' · ' + formatSize(byteLength(file.source)),
            note: file.source.length > PREVIEW_CHARS
                ? t('Aperçu partiel : le fichier téléchargé contient tout le contenu.')
                : '',
            long: lines > 12 || file.source.length > 900,
        };
    }

    function buildPreview(file) {
        if (file.preview === 'table') return tablePreview(file) || textPreview(file, true);
        if (file.preview === 'markdown') return documentPreview(file);
        return textPreview(file, file.preview === 'code');
    }

    function noteEl(className, text) {
        const el = document.createElement('div');
        el.className = className;
        el.textContent = text;
        return el;
    }

    /** Termine une carte : compteurs, aperçu, avertissements. Une seule fois. */
    function hydrateCard(card) {
        if (card.dataset.ready === '1') return;
        card.dataset.ready = '1';

        const file = readCard(card);
        const meta = card.querySelector('.file-card-meta');
        const empty = file.source.trim() === '';

        if (empty) {
            if (meta) meta.textContent = t('Fichier vide');
            card.classList.add('is-empty');
            card.querySelectorAll('.file-card-actions button').forEach(function (btn) { btn.disabled = true; });
            return;
        }

        // Bloc jamais refermé sur une réponse TERMINÉE : elle a été coupée.
        // Le fichier reste téléchargeable tel quel — on le dit, on ne le cache pas.
        if (card.dataset.closed === '0') {
            card.dataset.closed = '1';
            card.classList.add('is-incomplete');
            const rebuilt = document.createElement('div');
            rebuilt.innerHTML = cardHtml(file, file.source, true);
            const actions = rebuilt.querySelector('.file-card-actions');
            const head = card.querySelector('.file-card-head');
            if (actions && head) head.appendChild(actions);
            if (meta) meta.textContent = (file.refusal || file.format.label) + deckTemplateNote(file);
        }

        let preview = null;
        try {
            preview = buildPreview(file);
        } catch (_err) {
            preview = null; // un aperçu raté ne doit jamais empêcher de télécharger
        }

        if (preview && meta && !file.refusal) {
            meta.textContent = file.format.label + ' · ' + preview.meta + deckTemplateNote(file);
        }
        // « 1 245 mots », « 12 lignes × 4 colonnes » : la fenêtre « Version soignée » le reprend.
        if (preview && preview.meta) card.dataset.count = preview.meta;

        if (card.classList.contains('is-incomplete')) {
            card.appendChild(noteEl(
                'file-card-warning',
                t('Fichier incomplet : la réponse s’est arrêtée avant la fin. Vous pouvez le télécharger tel quel, ou regénérer la réponse.')
            ));
        }

        const status = document.createElement('div');
        status.className = 'file-card-status';
        status.setAttribute('role', 'status');
        status.hidden = true;
        card.appendChild(status);

        if (!preview) return;

        const wrap = document.createElement('div');
        wrap.className = 'file-card-preview file-preview-' + file.preview + (preview.long ? ' is-clamped' : '');
        wrap.appendChild(preview.node);
        card.appendChild(wrap);

        if (preview.note) card.appendChild(noteEl('file-card-note', preview.note));

        if (preview.long) {
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'file-card-toggle';
            toggle.setAttribute('aria-expanded', 'false');
            toggle.textContent = t('Afficher tout');
            card.appendChild(toggle);
        }
    }

    /** Pose un bouton de téléchargement sur chaque image d'une réponse. */
    function hydrateImages(container) {
        container.querySelectorAll('.md-render img').forEach(function (img) {
            if (img.closest('.msg-img-frame, .file-card, a')) return;
            const frame = document.createElement('span');
            frame.className = 'msg-img-frame';
            img.parentNode.insertBefore(frame, img);
            frame.appendChild(img);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'msg-img-download';
            btn.title = t('Télécharger l’image');
            btn.setAttribute('aria-label', t('Télécharger l’image'));
            btn.innerHTML = ICON_DOWNLOAD;
            frame.appendChild(btn);
        });
    }

    /**
     * À appeler une fois qu'une réponse est COMPLÈTE (chargement de page,
     * historique, fin d'écriture) — jamais pendant qu'elle s'écrit : le rendu
     * y est refait à chaque fragment, l'aperçu serait reconstruit cent fois.
     */
    function hydrate(container) {
        if (!container || !container.querySelectorAll) return;
        container.querySelectorAll('.file-card').forEach(function (card) {
            // Une carte DANS l'aperçu d'un document n'existe pas (previewDepth) ;
            // garde supplémentaire si le HTML venait d'ailleurs.
            if (card.parentElement && card.parentElement.closest('.file-card')) return;
            hydrateCard(card);
        });
        hydrateImages(container);
    }

    // ------------------------------------------------------------------
    // 5. Téléchargement
    // ------------------------------------------------------------------

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function refreshCsrfToken() {
        return fetch('/auth/csrf', { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.token) return false;
                const meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) meta.setAttribute('content', data.token);
                return true;
            })
            .catch(function () { return false; });
    }

    function filenameFromDisposition(header) {
        if (!header) return '';
        const utf8 = header.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8 && utf8[1]) {
            try { return decodeURIComponent(utf8[1]); } catch (_e) { return utf8[1]; }
        }
        const simple = header.match(/filename="?([^";]+)"?/i);
        return simple ? simple[1] : '';
    }

    /** Erreur présentable : message, code machine, formats de rechange. */
    function buildError(code, message, alternatives) {
        const err = new Error(message || t('Le fichier n’a pas pu être créé.'));
        err.code = code;
        err.alternatives = Array.isArray(alternatives) ? alternatives : [];
        return err;
    }

    /**
     * Demande au serveur de fabriquer un fichier. Résout { blob, filename } ;
     * rejette une erreur présentable (jamais une exception brute).
     */
    function requestBuild(request, retried) {
        const body = new FormData();
        body.append('name', request.name);
        body.append('origin', request.origin || request.name);
        body.append('language', request.language || '');
        body.append('source', request.source);

        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timer = controller ? setTimeout(function () { controller.abort(); }, BUILD_TIMEOUT_MS) : null;

        return fetch(CATALOG.endpoint, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken(), 'Accept': 'application/octet-stream, application/json' },
            body: body,
            signal: controller ? controller.signal : undefined,
        }).then(function (res) {
            if (timer) clearTimeout(timer);
            const type = String(res.headers.get('Content-Type') || '');

            // Session expirée : le serveur répond 401, ou redirige vers la page
            // de connexion (du HTML, que l'on ne doit surtout pas enregistrer).
            const login = res.redirected && /\/auth\/login/.test(res.url || '');
            if (res.status === 401 || login) {
                if (typeof root.showSessionExpiredModal === 'function') root.showSessionExpiredModal();
                throw buildError('session', t('Session expirée — rechargez la page.'));
            }

            if (!res.ok) {
                return res.json().catch(function () { return {}; }).then(function (data) {
                    if (res.status === 403 && data && data.code === 'csrf' && !retried) {
                        // Page ouverte depuis longtemps : jeton périmé. On en
                        // reprend un et on rejoue UNE fois.
                        return refreshCsrfToken().then(function (ok) {
                            if (!ok) throw buildError('session', t('Session expirée — rechargez la page.'));
                            return requestBuild(request, true);
                        });
                    }
                    throw buildError((data && data.code) || 'failed', data && data.error, data && data.alternatives);
                });
            }
            if (type.indexOf('text/html') === 0 && !/\.html?$/i.test(request.name)) {
                throw buildError('failed', '');
            }

            return res.blob().then(function (blob) {
                return {
                    blob: blob,
                    filename: filenameFromDisposition(res.headers.get('Content-Disposition')) || request.name,
                };
            });
        }, function (err) {
            if (timer) clearTimeout(timer);
            throw buildError(
                'network',
                t('Le serveur n’a pas répondu. Vérifiez votre connexion, puis réessayez.'),
                []
            );
        });
    }

    /** Enregistre un blob (feuille de partage dans l'application mobile). */
    function saveBlob(blob, filename) {
        if (typeof root.triggerBlobDownload === 'function') {
            root.triggerBlobDownload(blob, filename);
            return;
        }
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 8000);
    }

    /**
     * Issue de secours SANS serveur : le contenu tel quel, dans un fichier
     * texte. Toujours possible — c'est ce qui garantit qu'un clic sur
     * « Télécharger » ne se termine jamais sans rien.
     */
    function saveRaw(file) {
        const ext = rawExtension(file);
        const name = file.stem + '.' + ext;
        // BOM sur le CSV : sans lui, Excel lit les accents en Latin-1.
        const bom = (ext === 'csv' || ext === 'tsv' || ext === 'txt') ? '\uFEFF' : '';
        saveBlob(new Blob([bom + file.source], { type: 'text/plain;charset=utf-8' }), name);
        return name;
    }

    function toast(message, type) {
        if (typeof root.showToast === 'function') root.showToast(message, type || 'info');
    }

    // ---- Carte : états ----

    function setBusy(card, busy) {
        card.classList.toggle('is-busy', !!busy);
        const btn = card.querySelector('.file-download');
        const label = card.querySelector('.file-download-label');
        if (!btn || !label) return;
        if (busy) {
            if (!btn.dataset.label) btn.dataset.label = label.textContent;
            label.textContent = t('Préparation…');
        } else if (btn.dataset.label) {
            label.textContent = btn.dataset.label;
        }
        card.querySelectorAll('.file-card-actions button, .file-card-status button').forEach(function (el) {
            // Une « Version soignée » en route le reste.
            el.disabled = !!busy || el.classList.contains('is-sent') || el.classList.contains('is-busy');
        });
    }

    function flashDone(card) {
        const btn = card.querySelector('.file-download');
        const label = card.querySelector('.file-download-label');
        if (!btn || !label) return;
        const previous = btn.dataset.label || label.textContent;
        btn.classList.add('is-done');
        label.textContent = t('Téléchargé');
        setTimeout(function () {
            btn.classList.remove('is-done');
            label.textContent = previous;
        }, 2200);
    }

    function clearStatus(card) {
        const status = card.querySelector('.file-card-status');
        if (status) { status.hidden = true; status.textContent = ''; status.classList.remove('is-info'); }
    }

    /**
     * Échec affiché DANS la carte, avec ses issues : réessayer, un autre
     * format, ou le contenu brut. Jamais un bouton qui ne répond plus.
     */
    function showFailure(card, file, ext, err) {
        const status = card.querySelector('.file-card-status');
        if (!status) {
            toast(err.message, 'error');
            return;
        }
        status.textContent = '';
        status.hidden = false;
        status.classList.remove('is-info');

        const text = document.createElement('span');
        text.className = 'file-card-status-text';
        text.textContent = err.message || t('Le fichier n’a pas pu être créé.');
        status.appendChild(text);

        const actions = document.createElement('span');
        actions.className = 'file-card-status-actions';

        const add = function (label, className, data) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'file-status-btn ' + className;
            btn.textContent = label;
            Object.keys(data || {}).forEach(function (key) { btn.dataset[key] = data[key]; });
            actions.appendChild(btn);
        };

        // Réessayer n'a de sens que si rien n'a changé côté contenu : panne
        // réseau, serveur indisponible, échec inattendu.
        if (['network', 'failed', 'unavailable'].indexOf(err.code) !== -1) {
            add(t('Réessayer'), 'file-retry', { ext: ext });
        }
        (err.alternatives || []).filter(function (alt) { return alt !== ext; }).slice(0, 3).forEach(function (alt) {
            add(t('Télécharger en {format}', { format: menuLabel(alt) }), 'file-alt', { ext: alt });
        });
        add(t('Contenu brut (.{ext})', { ext: rawExtension(file) }), 'file-raw', {});

        status.appendChild(actions);
    }

    function downloadCard(card, ext) {
        if (card.classList.contains('is-busy')) return;
        const file = readCard(card);
        if (file.source.trim() === '') return;

        closeMenus();
        clearStatus(card);
        setBusy(card, true);

        requestBuild({
            name: file.stem + '.' + ext,
            origin: file.name,
            language: file.lang,
            source: file.source,
        }).then(function (result) {
            saveBlob(result.blob, result.filename);
            setBusy(card, false);
            flashDone(card);
        }, function (err) {
            setBusy(card, false);
            if (err.code === 'session') return;
            showFailure(card, file, ext, err);
        });
    }

    // ---- Présentation → gamma.app ----

    /** Une ligne d'état dans la carte : information (accent) ou échec (ambre). */
    function showGammaStatus(card, text, tone) {
        const status = card.querySelector('.file-card-status');
        if (!status) {
            toast(text, tone === 'info' ? 'info' : 'error');
            return;
        }
        status.textContent = '';
        status.hidden = false;
        status.classList.toggle('is-info', tone === 'info');
        status.appendChild(noteEl('file-card-status-text', text));
    }

    /**
     * Envoie la présentation au serveur, qui la confie à gamma.app. Résout la
     * réponse (job de suivi, message ⏳) ; rejette une erreur présentable.
     */
    function requestGamma(cid, request, retried) {
        const body = new FormData();
        body.append('name', request.name);
        body.append('origin', request.origin);
        body.append('language', request.language || '');
        body.append('source', request.source);

        return fetch('/chat/' + encodeURIComponent(cid) + '/gamma-deck', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken(), 'Accept': 'application/json' },
            body: body,
        }).then(function (res) {
            const login = res.redirected && /\/auth\/login/.test(res.url || '');
            if (res.status === 401 || login) {
                if (typeof root.showSessionExpiredModal === 'function') root.showSessionExpiredModal();
                throw buildError('session', t('Session expirée — rechargez la page.'));
            }
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (res.status === 403 && data && data.code === 'csrf' && !retried) {
                    return refreshCsrfToken().then(function (ok) {
                        if (!ok) throw buildError('session', t('Session expirée — rechargez la page.'));
                        return requestGamma(cid, request, true);
                    });
                }
                if (!res.ok || !data || !data.ok) {
                    throw buildError('failed', (data && data.error) || t('Gamma n’a pas pu être sollicité. Réessayez dans un instant.'));
                }
                return data;
            });
        }, function () {
            throw buildError('network', t('Le serveur n’a pas répondu. Vérifiez votre connexion, puis réessayez.'));
        });
    }

    /**
     * « Version soignée › Par Gamma » : la présentation part chez gamma.app,
     * qui la met en page sans en réécrire le texte ; le lien (et l'export
     * PowerPoint) arrive dans la conversation, suivi comme une génération de
     * l'outil Gamma (chat.js : attachJob, type `gamma`). Externe et payant
     * (crédits Gamma) : une confirmation d'abord. Le serveur choisit l'outil
     * et refuse ce qui dépasse son plafond — la page n'envoie que le texte
     * affiché. `button` = le bouton « Version soignée » de la carte.
     */
    function createWithGamma(card, button) {
        if (button.disabled || card.classList.contains('is-busy')) return;
        rememberPolishChoice('gamma');
        const file = readCard(card);
        if (file.source.trim() === '') return;
        const field = document.getElementById('convId');
        const cid = field ? String(field.value || '') : '';
        if (cid === '') {
            showGammaStatus(card, t('Conversation introuvable — rechargez la page.'), 'error');
            return;
        }

        closeMenus();
        if (typeof root.confirm === 'function' && !root.confirm(t('Créer cette présentation avec Gamma ?') + '\n\n'
            + t('Son contenu sera envoyé à gamma.app (service externe) et la création consommera des crédits Gamma. Comptez 1 à 3 minutes : le lien arrivera dans la conversation.'))) {
            return;
        }

        clearStatus(card);
        setPolishState(button, 'busy', t('Envoi à Gamma…'));
        requestGamma(cid, { name: file.name, origin: file.name, language: file.lang, source: file.source }).then(function (data) {
            setPolishState(button, 'idle');
            showGammaStatus(card, data.already
                ? t('Cette présentation est déjà en cours de création chez Gamma : le lien arrivera dans la conversation.')
                : t('Présentation envoyée à Gamma : le lien arrivera dans la conversation dans 1 à 3 minutes.'), 'info');
            // Le message ⏳ rejoint le fil — seulement s'il est encore affiché.
            const still = document.getElementById('convId');
            if (data.message && still && String(still.value) === cid && typeof root.showDriveOutcome === 'function') {
                root.showDriveOutcome(data);
            }
            if (data.job_id && typeof root.attachJob === 'function') {
                root.attachJob(String(data.job_id), { type: String(data.job_type || 'gamma'), convId: cid });
            }
        }, function (err) {
            setPolishState(button, 'idle');
            if (err.code === 'session') return;
            showGammaStatus(card, err.message, 'error');
        });
    }

    // ---- Présentation → éditeur de slides ----

    /** Fait de la présentation de la carte une présentation éditable ; résout {msg_id, created, deck}. */
    function requestEditableDeck(cid, request, retried) {
        const body = new FormData();
        body.append('name', request.name);
        body.append('origin', request.origin);
        body.append('language', request.language || '');
        body.append('source', request.source);

        return fetch('/chat/' + encodeURIComponent(cid) + '/deck-from-file', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken(), 'Accept': 'application/json' },
            body: body,
        }).then(function (res) {
            const login = res.redirected && /\/auth\/login/.test(res.url || '');
            if (res.status === 401 || login) {
                if (typeof root.showSessionExpiredModal === 'function') root.showSessionExpiredModal();
                throw buildError('session', t('Session expirée — rechargez la page.'));
            }
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (res.status === 403 && data && data.code === 'csrf' && !retried) {
                    return refreshCsrfToken().then(function (ok) {
                        if (!ok) throw buildError('session', t('Session expirée — rechargez la page.'));
                        return requestEditableDeck(cid, request, true);
                    });
                }
                if (!res.ok || !data || !data.ok || !data.msg_id) {
                    throw buildError('failed', (data && data.error) || t('La présentation n’a pas pu être ouverte dans l’éditeur. Réessayez dans un instant.'));
                }
                return data;
            });
        }, function () {
            throw buildError('network', t('Le serveur n’a pas répondu. Vérifiez votre connexion, puis réessayez.'));
        });
    }

    /**
     * « Modifier les slides » : le serveur construit la présentation éditable
     * (une carte de présentation rejoint le fil la première fois), puis le
     * volet l'ouvre, directement dans l'éditeur sur un écran qui le permet.
     */
    function editDeck(card, button) {
        if (button.disabled) return;
        const file = readCard(card);
        if (file.source.trim() === '') return;
        const field = document.getElementById('convId');
        const cid = field ? String(field.value || '') : '';
        if (cid === '') {
            showGammaStatus(card, t('Conversation introuvable — rechargez la page.'), 'error');
            return;
        }
        if (!root.Deck || typeof root.Deck.openFromMessage !== 'function') {
            showGammaStatus(card, t('L’éditeur de slides n’a pas pu se charger — rechargez la page.'), 'error');
            return;
        }

        closeMenus();
        clearStatus(card);
        button.disabled = true;
        button.classList.add('is-busy');
        requestEditableDeck(cid, { name: file.name, origin: file.name, language: file.lang, source: file.source }).then(function (data) {
            const still = document.getElementById('convId');
            if (data.created && data.deck && still && String(still.value) === cid && typeof root.appendMessage === 'function') {
                root.appendMessage('assistant', JSON.stringify(data.deck), [{ type: 'slide_deck', name: 'presentation.json' }], false, { messageId: data.msg_id });
            }
            root.Deck.openFromMessage(String(data.msg_id), { edit: true });
        }, function (err) {
            if (err.code !== 'session') showGammaStatus(card, err.message, 'error');
        }).then(function () {
            button.disabled = false;
            button.classList.remove('is-busy');
        });
    }

    // ---- Version soignée : un modèle (ou Gamma) compose le fichier ----

    /** Au-delà, la fenêtre de confirmation s'ouvre en feuille, en bas de l'écran. */
    const POLISH_NARROW = '(max-width: 640px)';

    const ICON_GAMMA = '<img src="/assets/img/gamma.svg" alt="" width="16" height="16" aria-hidden="true">';
    const ICON_CLOSE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></svg>';
    const ICON_INFO = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>';

    /** Dernier moyen choisi (« model » ou « gamma »), retenu par compte : le menu le signale. */
    function lastPolishChoice() {
        try { return root.localStorage.getItem('miaPolish:' + (root.CURRENT_USER_ID || '')) || ''; } catch (_e) { return ''; }
    }

    function rememberPolishChoice(choice) {
        try { root.localStorage.setItem('miaPolish:' + (root.CURRENT_USER_ID || ''), choice); } catch (_e) { /* navigation privée */ }
    }

    /** Bouton « Version soignée » occupé (envoi en cours) ou au repos. */
    function setPolishState(button, state, text) {
        const label = button.querySelector('.file-polish-label');
        button.classList.toggle('is-busy', state === 'busy');
        button.disabled = state === 'busy';
        const value = state === 'busy' && text ? text : t('Version soignée');
        if (label) label.textContent = value;
        button.setAttribute('aria-label', value);
    }

    /**
     * POST d'un formulaire vers une route JSON du chat : session expirée
     * (fenêtre existante), jeton CSRF périmé (rejoué UNE fois), quota épuisé
     * (fenêtre du quota). Une liste part en `nom[]`. Résout la réponse ;
     * rejette une erreur présentable.
     */
    function postJson(url, fields, failure, retried) {
        const body = new FormData();
        Object.keys(fields).forEach(function (name) {
            const value = fields[name];
            if (Array.isArray(value)) {
                value.forEach(function (item) { body.append(name + '[]', item); });
            } else if (value !== undefined && value !== null && value !== '') {
                body.append(name, value);
            }
        });

        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken(), 'Accept': 'application/json' },
            body: body,
        }).then(function (res) {
            const login = res.redirected && /\/auth\/login/.test(res.url || '');
            if (res.status === 401 || login) {
                if (typeof root.showSessionExpiredModal === 'function') root.showSessionExpiredModal();
                throw buildError('session', t('Session expirée — rechargez la page.'));
            }
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (res.status === 403 && data && data.code === 'csrf' && !retried) {
                    return refreshCsrfToken().then(function (ok) {
                        if (!ok) throw buildError('session', t('Session expirée — rechargez la page.'));
                        return postJson(url, fields, failure, true);
                    });
                }
                if (res.status === 429 && data && data.quota_exceeded) {
                    if (typeof root.handleQuotaExceededPayload === 'function') root.handleQuotaExceededPayload(data);
                    throw buildError('session', '');
                }
                if (!res.ok || !data || !data.ok) {
                    throw buildError('failed', (data && data.error) || failure);
                }
                return data;
            });
        }, function () {
            throw buildError('network', t('Le serveur n’a pas répondu. Vérifiez votre connexion, puis réessayez.'));
        });
    }

    /** Conversation affichée et message qui porte la carte : le serveur ne lit que ce qui le précède. */
    function cardContext(card) {
        const field = document.getElementById('convId');
        const holder = card.closest('.message');
        const id = holder ? holder.getAttribute('data-message-id') || '' : '';

        return { cid: field ? String(field.value || '') : '', messageId: /^\d+$/.test(id) ? id : '' };
    }

    /** Demande la réalisation au serveur ; résout {job_id, message} ou rejette une erreur présentable. */
    function requestNative(cid, request) {
        return postJson('/chat/' + encodeURIComponent(cid) + '/native-deck', {
            name: request.name,
            origin: request.origin,
            language: request.language || '',
            source: request.source,
            llm: request.llm || '',
            message_id: request.messageId || '',
            model: request.model || '',
            exclude: request.exclude || [],
            company: request.company === false ? '0' : '1',
            note: request.note || '',
        }, t('Le modèle n’a pas pu être sollicité. Réessayez dans un instant.'));
    }

    /**
     * Fenêtre « Version soignée » préparée par le serveur (lecture seule) :
     * modèles possibles et celui choisi d'office, estimation, ce qui part.
     * Gardée sur la carte tant que le modèle du composer ne change pas
     * (refreshNative l'oublie) ; une erreur n'est pas gardée.
     */
    function ensurePlan(card) {
        const ctx = cardContext(card);
        const llm = currentLlm().slug;
        const key = llm + '|' + ctx.messageId;
        if (card.__plan && card.__plan.key === key) return card.__plan.promise;
        if (ctx.cid === '') return Promise.reject(buildError('failed', t('Conversation introuvable — rechargez la page.')));

        const file = readCard(card);
        const entry = { key: key, data: null, promise: null };
        entry.promise = postJson('/chat/' + encodeURIComponent(ctx.cid) + '/native-deck/plan', {
            name: file.name,
            origin: file.name,
            language: file.lang || '',
            llm: llm,
            message_id: ctx.messageId,
        }, t('La fenêtre n’a pas pu être préparée. Réessayez dans un instant.')).then(function (data) {
            entry.data = data;
            const button = card.querySelector('.file-polish');
            if (button && !button.classList.contains('is-busy')) {
                button.title = polishTitle(button.dataset.kind || 'pptx', button.dataset.gamma === '1', data);
            }
            return data;
        }, function (err) {
            if (card.__plan === entry) card.__plan = null;
            throw err;
        });
        card.__plan = entry;

        return entry.promise;
    }

    // -- Menu : par un modèle, ou par Gamma (présentation, outil Gamma ouvert) --

    function polishItemHtml(act, icon, label, pill, sub, meta, disabled) {
        return '<button type="button" class="file-polish-item" role="menuitem" data-act="' + act + '"'
            + (disabled ? ' aria-disabled="true"' : '') + '>'
            + '<span class="file-polish-item-icon">' + icon + '</span>'
            + '<span class="file-polish-item-label">' + esc(label)
            + (pill ? '<span class="file-polish-pill">' + esc(t('Dernier choix')) + '</span>' : '') + '</span>'
            + '<span class="file-polish-item-meta">' + meta.map(function (m) { return '<span>' + esc(m) + '</span>'; }).join('') + '</span>'
            + '<span class="file-polish-item-sub">' + esc(sub) + '</span>'
            + '</button>';
    }

    function renderPolishMenu(card, menu) {
        const entry = card.__plan;
        const plan = entry && entry.data;
        const can = nativeAvailable();
        const last = can ? lastPolishChoice() : '';
        const chosen = planModel(plan);
        let label = t('Par un modèle');
        let sub = t('Préparation…');
        let meta = [t('1 à 3 min')];
        if (!can) {
            sub = noModelReason();
            meta = [];
        } else if (menu.__error) {
            sub = menu.__error;
            meta = [];
        } else if (plan) {
            label = chosen ? t('Par {model}', { model: chosen.label }) : t('Par le modèle de votre choix');
            sub = plan.summary || '';
            if (chosen && chosen.estimate) meta.push(chosen.estimate);
        }

        menu.innerHTML = polishItemHtml('model', ICON_SPARK, label, last === 'model', sub, meta, !can)
            + polishItemHtml('gamma', ICON_GAMMA, t('Par Gamma'), last === 'gamma',
                t('Thème et illustrations de Gamma, votre texte gardé tel quel'), [t('1 à 3 min'), t('Crédits Gamma')], false)
            + '<div class="file-polish-menu-foot">' + esc(t('La version rapide de MIA, immédiate et sans crédit, reste au bouton Télécharger.')) + '</div>';
    }

    function openPolishMenu(card, button) {
        const wrap = button.parentNode;
        let menu = wrap.querySelector('.file-polish-menu');
        if (!menu) {
            menu = document.createElement('div');
            menu.className = 'file-polish-menu';
            menu.setAttribute('role', 'menu');
            menu.setAttribute('aria-label', t('Version soignée'));
            menu.hidden = true;
            wrap.appendChild(menu);
        }
        closePolishPanel();
        closeMenus(menu);
        menu.__error = '';
        renderPolishMenu(card, menu);
        menu.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        if (menu.scrollIntoView) menu.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        const first = menu.querySelector('.file-polish-item:not([aria-disabled="true"])');
        if (first) first.focus({ preventScroll: true });

        if (nativeAvailable()) {
            ensurePlan(card).then(function () {
                if (!menu.hidden) renderPolishMenu(card, menu);
            }, function (err) {
                if (menu.hidden) return;
                menu.__error = err.message || '';
                renderPolishMenu(card, menu);
            });
        }
    }

    // -- Fenêtre de confirmation : par quel modèle, ce qui part, pour combien --

    let activePanel = null;
    let panelSeq = 0;

    function closePolishPanel(panel) {
        const open = panel || activePanel;
        if (!open) return;
        if (open.__backdrop) open.__backdrop.remove();
        if (open.__onViewport && root.visualViewport) {
            root.visualViewport.removeEventListener('resize', open.__onViewport);
            root.visualViewport.removeEventListener('scroll', open.__onViewport);
        }
        if (open.__button) open.__button.setAttribute('aria-expanded', 'false');
        open.remove();
        if (activePanel === open) activePanel = null;
    }

    /** Feuille au-dessus du clavier virtuel : la zone visible (visualViewport), en pixels de page. */
    function fitSheet(panel) {
        const vv = root.visualViewport;
        if (!vv) return;
        const z = root.miaUiZoom ? root.miaUiZoom() : 1;
        const hidden = Math.max(0, root.innerHeight - vv.height - vv.offsetTop);
        panel.style.bottom = Math.round(hidden / z) + 'px';
        panel.style.maxHeight = Math.round((vv.height * 0.92) / z) + 'px';
    }

    function openPolishPanel(card, button) {
        closeMenus();
        closePolishPanel();
        const file = readCard(card);
        const panel = document.createElement('section');
        panel.className = 'file-polish-panel';
        panel.tabIndex = -1;
        panel.__card = card;
        panel.__button = button;
        panel.__kind = nativeKind(file) || 'pptx';
        panel.__seq = ++panelSeq;

        const sheet = !!(root.matchMedia && root.matchMedia(POLISH_NARROW).matches);
        if (sheet) {
            panel.classList.add('is-sheet');
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');
            const backdrop = document.createElement('div');
            backdrop.className = 'file-polish-backdrop';
            backdrop.addEventListener('click', function () { closePolishPanel(panel); });
            document.body.appendChild(backdrop);
            document.body.appendChild(panel);
            panel.__backdrop = backdrop;
            if (root.visualViewport) {
                panel.__onViewport = function () { fitSheet(panel); };
                root.visualViewport.addEventListener('resize', panel.__onViewport);
                root.visualViewport.addEventListener('scroll', panel.__onViewport);
                fitSheet(panel);
            }
        } else {
            panel.setAttribute('role', 'region');
            const head = card.querySelector('.file-card-head');
            if (head) head.insertAdjacentElement('afterend', panel);
            else card.appendChild(panel);
        }
        panel.setAttribute('aria-labelledby', 'polishTitle' + panel.__seq);
        activePanel = panel;
        button.setAttribute('aria-expanded', 'true');

        renderPanel(panel, null, '');
        panel.focus({ preventScroll: true });
        if (!sheet && panel.scrollIntoView) panel.scrollIntoView({ block: 'nearest' });
        loadPanel(panel);
    }

    function loadPanel(panel) {
        ensurePlan(panel.__card).then(function (plan) {
            if (activePanel === panel) renderPanel(panel, plan, '');
        }, function (err) {
            if (activePanel !== panel) return;
            if (err.code === 'session') { closePolishPanel(panel); return; }
            renderPanel(panel, null, err.message || t('La fenêtre n’a pas pu être préparée. Réessayez dans un instant.'));
        });
    }

    function panelHead(panel) {
        const intro = panel.__kind === 'xlsx'
            ? t('Le modèle compose lui-même le classeur : types, formules, mise en forme. Vérifiez ce qui part avant de lancer.')
            : t('Le modèle compose lui-même la présentation, avec sa propre mise en page. Vérifiez ce qui part avant de lancer.');

        return '<div class="file-polish-head">'
            + '<span class="file-polish-icon" aria-hidden="true">' + ICON_SPARK + '</span>'
            + '<div class="file-polish-titles"><h3 class="file-polish-title" id="polishTitle' + panel.__seq + '">' + esc(t('Version soignée')) + '</h3>'
            + '<p class="file-polish-intro">' + esc(intro) + '</p></div>'
            + '<button type="button" class="file-polish-close" aria-label="' + esc(t('Fermer')) + '" title="' + esc(t('Fermer')) + '">' + ICON_CLOSE + '</button>'
            + '</div>';
    }

    function rowHtml(attrs, name, note, tag, checked, locked) {
        return '<label class="file-polish-row' + (locked ? ' is-locked' : '') + '">'
            + '<input type="checkbox"' + attrs + (checked ? ' checked' : '') + (locked ? ' disabled' : '') + '>'
            + '<span class="file-polish-row-text"><span class="file-polish-row-name">' + esc(name) + '</span>'
            + (note ? '<span class="file-polish-row-note">' + esc(note) + '</span>' : '') + '</span>'
            + (tag ? '<span class="file-polish-tag">' + esc(tag) + '</span>' : '')
            + '</label>';
    }

    function renderPanel(panel, plan, error) {
        panel.__plan = plan;
        if (error) {
            panel.innerHTML = panelHead(panel)
                + '<div class="file-polish-error" role="alert">' + esc(error) + '</div>'
                + '<div class="file-polish-actions">'
                + '<button type="button" class="file-polish-cancel">' + esc(t('Fermer')) + '</button>'
                + '<button type="button" class="file-polish-retry">' + esc(t('Réessayer')) + '</button></div>';
            return;
        }
        if (!plan) {
            panel.innerHTML = panelHead(panel)
                + '<div class="file-polish-loading" role="status"><span class="file-polish-spinner" aria-hidden="true"></span>'
                + esc(t('Préparation…')) + '</div>';
            return;
        }

        const xlsx = panel.__kind === 'xlsx';
        const name = 'polishModel' + panel.__seq;
        const card = panel.__card;
        const sheet = panel.classList.contains('is-sheet');
        const models = (plan.models || []).map(function (m) {
            return '<label class="file-polish-model">'
                + '<input type="radio" name="' + name + '" value="' + esc(m.slug) + '" data-polish-model' + (m.slug === plan.selected ? ' checked' : '') + '>'
                + '<span class="file-polish-model-text"><span class="file-polish-model-name">' + esc(m.label) + '</span>'
                + (m.note ? '<span class="file-polish-model-note">' + esc(m.note) + '</span>' : '') + '</span>'
                + (m.estimate ? '<span class="file-polish-model-cost">' + esc(m.estimate) + '</span>' : '')
                + '</label>';
        }).join('');
        // Feuille (téléphone) : le modèle choisi d'office en une ligne, la liste derrière « Changer ».
        const compact = sheet && !!plan.selected && (plan.models || []).length > 1;

        let rows = rowHtml('', t('Le texte de la carte'),
            t('Rédigé dans la conversation') + (card.dataset.count ? ' · ' + card.dataset.count : ''), t('Toujours'), true, true);
        if (plan.request) rows += rowHtml('', t('Votre demande'), plan.request, t('Toujours'), true, true);
        (plan.files || []).forEach(function (f) {
            rows += rowHtml(' data-polish-file value="' + esc(f.name) + '"', f.name, f.note, '', !f.older, false);
        });
        if (plan.company) rows += rowHtml(' data-polish-company', plan.company.label, plan.company.note, '', true, false);

        const placeholder = xlsx ? t('Ex. : un graphique par trimestre') : t('Ex. : style sobre, un chiffre clé par slide');

        panel.innerHTML = panelHead(panel)
            + '<div class="file-polish-section">'
            + '<span class="file-polish-heading">' + esc(t('Réalisée par')) + '</span>'
            + (plan.notice ? '<div class="file-polish-notice">' + ICON_INFO + '<span>' + esc(plan.notice) + '</span></div>' : '')
            + (compact
                ? '<div class="file-polish-chosen"><span class="file-polish-chosen-text"><span class="file-polish-model-name" data-polish-chosen-name></span>'
                    + '<span class="file-polish-model-note" data-polish-chosen-meta></span></span>'
                    + '<button type="button" class="file-polish-change" aria-expanded="false">' + esc(t('Changer')) + '</button></div>'
                : '')
            + '<div class="file-polish-models" role="radiogroup" aria-label="' + esc(t('Modèle qui réalise le fichier')) + '"' + (compact ? ' hidden' : '') + '>' + models + '</div>'
            + '</div>'
            + '<div class="file-polish-section">'
            + '<span class="file-polish-heading" data-polish-provider></span>'
            + '<div class="file-polish-list">' + rows + '</div>'
            + ((plan.files || []).length || plan.company ? '<span class="file-polish-hint" data-polish-hint></span>' : '')
            + '</div>'
            + '<div class="file-polish-section">'
            + '<label class="file-polish-heading" for="polishNote' + panel.__seq + '">' + esc(t('Une précision pour la mise en page ?'))
            + ' <span class="file-polish-optional">' + esc(t('Facultatif')) + '</span></label>'
            + '<input id="polishNote' + panel.__seq + '" type="text" class="file-polish-note" maxlength="300" autocomplete="off" placeholder="' + esc(placeholder) + '">'
            + '</div>'
            + '<div class="file-polish-foot">'
            + '<div class="file-polish-cost"><span class="file-polish-cost-main" data-polish-cost></span>'
            + '<span class="file-polish-cost-basis" data-polish-basis></span></div>'
            + '<div class="file-polish-error" role="alert" hidden></div>'
            + '<div class="file-polish-actions">'
            + '<button type="button" class="file-polish-cancel">' + esc(t('Annuler')) + '</button>'
            + '<button type="button" class="file-polish-launch">' + ICON_SPARK + '<span class="file-polish-launch-label"></span></button>'
            + '</div></div>';
        syncPanel(panel);
    }

    /** Le modèle coché a changé : éditeur, estimation et bouton suivent. */
    function syncPanel(panel) {
        const plan = panel.__plan;
        if (!plan) return;
        const radio = panel.querySelector('input[data-polish-model]:checked');
        const chosen = radio ? planModel(plan, radio.value) : null;
        const provider = chosen ? chosen.provider : '';
        const xlsx = panel.__kind === 'xlsx';
        const sheet = panel.classList.contains('is-sheet');
        const setText = function (selector, text) {
            const el = panel.querySelector(selector);
            if (el) el.textContent = text;
        };

        setText('[data-polish-provider]', provider ? t('Ce qui part chez {provider}', { provider: provider }) : t('Ce qui part chez l’éditeur du modèle'));
        setText('[data-polish-hint]', (plan.files || []).length
            ? t('Fichiers joints avant la carte, dans cette conversation. Les fichiers envoyés sont effacés chez {provider} une fois le fichier reçu.', { provider: provider || t('l’éditeur') })
            : t('Les fichiers envoyés sont effacés chez {provider} une fois le fichier reçu.', { provider: provider || t('l’éditeur') }));
        setText('[data-polish-chosen-name]', chosen ? chosen.label : '');
        setText('[data-polish-chosen-meta]', chosen ? [chosen.estimate, t('1 à 3 min')].filter(Boolean).join(' · ') : '');
        setText('[data-polish-cost]', chosen
            ? [chosen.estimate, t('1 à 3 min')].filter(Boolean).join(' · ')
            : t('Choisissez le modèle qui réalisera le fichier.'));
        setText('[data-polish-basis]', chosen
            ? [chosen.basis, t('fichier gardé {days} jours', { days: String(plan.days || 30) })].filter(Boolean).join(' · ')
            : '');

        const launch = panel.querySelector('.file-polish-launch');
        if (launch) {
            launch.disabled = !chosen || panel.classList.contains('is-busy');
            const base = xlsx ? t('Lancer la réalisation') : t('Lancer la mise en page');
            setText('.file-polish-launch-label', sheet && chosen && chosen.estimate ? t('Lancer · {estimate}', { estimate: chosen.estimate }) : base);
        }
    }

    function panelError(panel, message) {
        const box = panel.querySelector('.file-polish-foot .file-polish-error');
        if (!box) return;
        box.textContent = message;
        box.hidden = message === '';
    }

    /**
     * « Lancer » : le modèle coché, les fichiers restés cochés, le modèle de
     * l'entreprise s'il l'est, la précision. Le serveur revalide tout (le
     * modèle doit être l'un de ceux qu'il a proposés) ; le lien arrive dans la
     * conversation, suivi comme une génération (chat.js, type `native_deck`).
     */
    function launchFromPanel(panel) {
        const plan = panel.__plan;
        if (!plan || panel.classList.contains('is-busy')) return;
        const card = panel.__card;
        const button = panel.__button;
        const radio = panel.querySelector('input[data-polish-model]:checked');
        const chosen = radio ? planModel(plan, radio.value) : null;
        if (!chosen) {
            panelError(panel, t('Choisissez le modèle qui réalisera le fichier.'));
            return;
        }
        const file = readCard(card);
        const ctx = cardContext(card);
        if (ctx.cid === '' || file.source.trim() === '') {
            panelError(panel, t('Conversation introuvable — rechargez la page.'));
            return;
        }
        const exclude = Array.prototype.slice.call(panel.querySelectorAll('input[data-polish-file]'))
            .filter(function (input) { return !input.checked; })
            .map(function (input) { return input.value; });
        const company = panel.querySelector('input[data-polish-company]');
        const note = panel.querySelector('.file-polish-note');
        const xlsx = panel.__kind === 'xlsx';

        rememberPolishChoice('model');
        panelError(panel, '');
        panel.classList.add('is-busy');
        panel.querySelectorAll('input, button:not(.file-polish-close)').forEach(function (el) { el.disabled = true; });
        setPolishState(button, 'busy', t('Envoi au modèle…'));
        clearStatus(card);

        requestNative(ctx.cid, {
            name: file.name,
            origin: file.name,
            language: file.lang,
            source: file.source,
            llm: currentLlm().slug,
            messageId: ctx.messageId,
            model: chosen.slug,
            exclude: exclude,
            company: company ? company.checked : true,
            note: note ? note.value : '',
        }).then(function (data) {
            closePolishPanel(panel);
            setPolishState(button, 'idle');
            let status;
            if (xlsx) {
                status = data.already
                    ? t('Ce classeur est déjà en cours de réalisation : le fichier arrivera dans la conversation.')
                    : t('{model} réalise le classeur : le fichier arrivera dans la conversation dans une à trois minutes.', { model: chosen.label });
            } else {
                status = data.already
                    ? t('Cette présentation est déjà en cours de réalisation : le fichier arrivera dans la conversation.')
                    : t('{model} réalise la présentation : le fichier arrivera dans la conversation dans une à trois minutes.', { model: chosen.label });
            }
            showGammaStatus(card, status, 'info');
            const still = document.getElementById('convId');
            if (data.message && still && String(still.value) === ctx.cid && typeof root.showDriveOutcome === 'function') {
                root.showDriveOutcome(data);
            }
            if (data.job_id && typeof root.attachJob === 'function') {
                root.attachJob(String(data.job_id), { type: String(data.job_type || 'native_deck'), convId: ctx.cid });
            }
        }, function (err) {
            setPolishState(button, 'idle');
            if (err.code === 'session') {
                closePolishPanel(panel);
                return;
            }
            panel.classList.remove('is-busy');
            panel.querySelectorAll('input, button').forEach(function (el) {
                if (!el.closest('.file-polish-row.is-locked')) el.disabled = false;
            });
            syncPanel(panel);
            panelError(panel, err.message);
        });
    }

    /**
     * Clic sur « Version soignée » : un menu quand Gamma est aussi proposé,
     * sinon directement la fenêtre de confirmation. Rien ne peut le faire :
     * la raison, dans la carte.
     */
    function openPolish(card, button) {
        if (button.disabled || card.classList.contains('is-busy')) return;
        if (readCard(card).source.trim() === '') return;
        const gamma = button.dataset.gamma === '1';
        if (gamma) {
            const menu = button.parentNode.querySelector('.file-polish-menu');
            if (menu && !menu.hidden) { closeMenus(); return; }
            openPolishMenu(card, button);
            return;
        }
        if (!nativeAvailable()) {
            showGammaStatus(card, noModelReason(), 'error');
            return;
        }
        if (activePanel && activePanel.__card === card) {
            closePolishPanel();
            return;
        }
        openPolishPanel(card, button);
    }

    /** Choix dans le menu : la fenêtre de confirmation (modèle), ou l'envoi à Gamma. */
    function choosePolish(card, item) {
        const button = card.querySelector('.file-polish');
        if (!button) return;
        if (item.getAttribute('aria-disabled') === 'true') {
            if (item.dataset.act === 'model') showGammaStatus(card, noModelReason(), 'error');
            closeMenus();
            return;
        }
        closeMenus();
        if (item.dataset.act === 'gamma') {
            createWithGamma(card, button);
            return;
        }
        openPolishPanel(card, button);
    }

    // La fenêtre n'existe qu'à une largeur : passer de l'une à l'autre la referme.
    if (root.matchMedia) {
        const narrow = root.matchMedia(POLISH_NARROW);
        const onWidth = function () { closePolishPanel(); };
        if (narrow.addEventListener) narrow.addEventListener('change', onWidth);
        else if (narrow.addListener) narrow.addListener(onWidth);
    }

    // Préparer la fenêtre dès que la souris s'attarde sur le bouton (ou que le
    // clavier y arrive) : elle s'ouvre alors déjà remplie.
    let prefetchTimer = 0;
    const prefetch = function (button) {
        const card = button.closest('.file-card');
        if (!card || button.classList.contains('is-unavailable') || !nativeAvailable()) return;
        ensurePlan(card).catch(function () { /* la fenêtre le redira */ });
    };
    document.addEventListener('pointerover', function (event) {
        const button = event.target && event.target.closest ? event.target.closest('.file-polish') : null;
        clearTimeout(prefetchTimer);
        if (button) prefetchTimer = setTimeout(function () { prefetch(button); }, 200);
    });
    document.addEventListener('focusin', function (event) {
        const button = event.target && event.target.closest ? event.target.closest('.file-polish') : null;
        if (button) prefetch(button);
    });

    /**
     * Lien d'un fichier réalisé par le modèle (`/chat/{id}/presentation/{jeton}`, présentation ou classeur) :
     * téléchargé par le chemin unique d'enregistrement (triggerBlobDownload,
     * feuille de partage dans l'application mobile, où un lien de
     * téléchargement ne mène nulle part).
     */
    const NATIVE_LINK = /^\/chat\/\d+\/presentation\/[0-9a-f]{32}$/;

    function downloadNativeLink(link) {
        const href = link.getAttribute('href') || '';
        const fallback = (link.textContent || '').trim() || 'presentation.pptx';
        fetch(href, { credentials: 'same-origin' }).then(function (res) {
            if (!res.ok) {
                return res.text().then(function (text) { toast(text || t('Ce fichier n’existe plus.'), 'error'); });
            }
            const disposition = res.headers.get('Content-Disposition') || '';
            const star = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
            let name = fallback;
            try { if (star) name = decodeURIComponent(star[1]); } catch (_e) { name = fallback; }
            return res.blob().then(function (blob) {
                if (typeof root.triggerBlobDownload === 'function') root.triggerBlobDownload(blob, name);
                else saveBlob(blob, name);
            });
        }, function () {
            toast(t('Le serveur n’a pas répondu. Vérifiez votre connexion, puis réessayez.'), 'error');
        });
    }

    // ---- Bloc de code ordinaire ----

    function codeFile(button) {
        const pre = button.closest('pre');
        const code = pre ? pre.querySelector('code') : null;
        const lang = button.dataset.lang || '';
        const file = describe('', lang);
        file.source = code ? code.textContent : '';
        return file;
    }

    /**
     * Téléchargement d'un bloc ordinaire. Pas de carte où afficher un échec :
     * en cas de panne le contenu est enregistré tel quel, et on le dit.
     */
    function downloadCode(button, ext) {
        if (button.disabled) return;
        const file = codeFile(button);
        if (file.source.trim() === '') return;

        closeMenus();
        button.disabled = true;
        requestBuild({
            name: file.stem + '.' + ext,
            origin: file.name,
            language: file.lang,
            source: file.source,
        }).then(function (result) {
            saveBlob(result.blob, result.filename);
            button.disabled = false;
        }, function (err) {
            button.disabled = false;
            if (err.code === 'session') return;
            const name = saveRaw(file);
            toast(t('Conversion impossible : le contenu a été enregistré tel quel ({nom}).', { nom: name }), 'error');
        });
    }

    // ---- Images ----

    function imageName(blob, url) {
        const sub = String(blob.type || '').split('/')[1] || '';
        let ext = sub.split('+')[0].replace('jpeg', 'jpg');
        if (!/^[a-z0-9]{2,5}$/.test(ext)) {
            const fromUrl = String(url || '').split('?')[0].match(/\.([a-z0-9]{2,5})$/i);
            ext = fromUrl ? fromUrl[1].toLowerCase() : 'png';
        }
        const now = new Date();
        const pad = function (n) { return String(n).padStart(2, '0'); };
        return 'image-' + now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate())
            + '-' + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds()) + '.' + ext;
    }

    /**
     * Télécharge une image. Une image d'un autre site refuse parfois d'être
     * lue par le script (CORS) : on l'ouvre alors dans un nouvel onglet, d'où
     * elle s'enregistre — plutôt qu'un bouton sans effet.
     */
    function downloadImage(url, button) {
        if (!url) return Promise.resolve(false);
        if (button) button.disabled = true;
        return fetch(url).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.blob();
        }).then(function (blob) {
            saveBlob(blob, imageName(blob, url));
            return true;
        }).catch(function () {
            if (/^https?:/i.test(url) || url.charAt(0) === '/') {
                root.open(url, '_blank', 'noopener');
                toast(t('Téléchargement direct impossible : l’image s’ouvre dans un nouvel onglet.'), 'info');
            } else {
                toast(t('Téléchargement impossible.'), 'error');
            }
            return false;
        }).then(function (ok) {
            if (button) button.disabled = false;
            return ok;
        });
    }

    // ------------------------------------------------------------------
    // 6. Copie et export d'une réponse
    // ------------------------------------------------------------------

    /**
     * Prépare un CLONE de réponse pour le presse-papiers ou un export PDF /
     * Word : une carte y devient le nom du fichier suivi de son aperçu, sans
     * boutons ni source cachée (elle doublerait le contenu). Appelé par
     * neutralizeClipboardStyles (chat.js) — donc par les deux chemins de copie
     * et par les deux exports.
     */
    function flattenForExport(scope) {
        if (!scope || !scope.querySelectorAll) return scope;

        scope.querySelectorAll('.msg-img-download, .btn-download-code, .file-menu').forEach(function (el) { el.remove(); });
        scope.querySelectorAll('.msg-img-frame').forEach(function (frame) {
            while (frame.firstChild) frame.parentNode.insertBefore(frame.firstChild, frame);
            frame.remove();
        });

        scope.querySelectorAll('.file-card').forEach(function (card) {
            const out = document.createElement('div');
            if (card.querySelector('.file-card-head') && card.dataset.name) {
                const title = document.createElement('p');
                const strong = document.createElement('strong');
                strong.appendChild(document.createTextNode(card.dataset.name));
                title.appendChild(strong);
                out.appendChild(title);
            }
            const preview = card.querySelector('.file-card-preview');
            const source = card.querySelector('.file-card-source');
            if (preview) {
                while (preview.firstChild) out.appendChild(preview.firstChild);
            } else if (source) {
                source.hidden = false;
                source.removeAttribute('class');
                out.appendChild(source);
            }
            card.parentNode.replaceChild(out, card);
        });

        return scope;
    }

    // ------------------------------------------------------------------
    // 7. Interactions (délégation : les cartes sont recréées à chaque rendu)
    // ------------------------------------------------------------------

    /** Bouton qui a ouvert un menu (celui d'un bloc de code vit hors de la carte). */
    function openerOf(menu) {
        return menu.__opener
            || (menu.parentNode ? menu.parentNode.querySelector('[aria-haspopup="menu"]') : null);
    }

    function closeMenus(except) {
        document.querySelectorAll('.file-menu:not([hidden]), .file-polish-menu:not([hidden])').forEach(function (menu) {
            if (menu === except) return;
            menu.hidden = true;
            const opener = openerOf(menu);
            if (opener) opener.setAttribute('aria-expanded', 'false');
            // Menu d'un bloc de code : créé à la demande, retiré à la fermeture.
            if (menu.classList.contains('is-floating')) menu.remove();
        });
    }

    function openMenu(opener, menu) {
        closeMenus(menu);
        menu.hidden = false;
        opener.setAttribute('aria-expanded', 'true');
        // Carte en bas du fil : le menu s'ouvrirait sous le bord visible.
        if (!menu.classList.contains('is-floating') && menu.scrollIntoView) {
            menu.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
        const first = menu.querySelector('.file-menu-item');
        if (first) first.focus({ preventScroll: true });
    }

    /**
     * Menu des formats d'un bloc de code ordinaire. Posé sur la PAGE et non
     * dans le bloc : un bloc de code défile (`overflow`), un menu placé dedans
     * y serait rogné — invisible sous un bloc d'une seule ligne.
     */
    function openCodeMenu(button, file) {
        const holder = document.createElement('div');
        holder.innerHTML = menuHtml(file.targets, file.ext);
        const menu = holder.firstChild;
        menu.classList.add('is-floating');
        menu.__opener = button;
        button.setAttribute('aria-haspopup', 'menu');
        document.body.appendChild(menu);
        openMenu(button, menu);

        // Pixels d'ÉCRAN jusqu'à l'écriture, divisés alors par la taille
        // d'affichage (zoom de <html>, cf. head-meta.php).
        const z = window.miaUiZoom ? window.miaUiZoom() : 1;
        const rect = button.getBoundingClientRect();
        const width = (menu.offsetWidth || 190) * z;
        const height = (menu.offsetHeight || 80) * z;
        const below = rect.bottom + 4 + height <= window.innerHeight;
        menu.style.top = Math.round((below ? rect.bottom + 4 : Math.max(8, rect.top - height - 4)) / z) + 'px';
        menu.style.left = Math.round(Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8)) / z) + 'px';
    }

    function copySource(button, text) {
        const done = function () {
            const previous = button.innerHTML;
            button.innerHTML = ICON_CHECK;
            button.classList.add('is-done');
            setTimeout(function () {
                button.innerHTML = previous;
                button.classList.remove('is-done');
            }, 1800);
        };
        const fail = function () { toast(t('Impossible de copier le contenu.'), 'error'); };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, fail);
        } else {
            fail();
        }
    }

    // Images — en phase de CAPTURE : la visionneuse se referme (et vide son
    // image) au moindre clic qui lui parvient, et le fil ouvre la visionneuse
    // sur un clic d'image. Le bouton doit donc agir AVANT eux, et leur
    // retirer l'évènement.
    document.addEventListener('click', function (event) {
        const target = event.target;
        if (!target || !target.closest) return;

        // Présentation réalisée par le modèle : téléchargée, jamais ouverte
        // (le fil ouvrirait le lien dans un onglet, l'application mobile nulle part).
        const link = target.closest('a[href]');
        if (link && NATIVE_LINK.test(link.getAttribute('href') || '')) {
            event.preventDefault();
            event.stopPropagation();
            downloadNativeLink(link);
            return;
        }

        const imgBtn = target.closest('.msg-img-download');
        const lightboxBtn = imgBtn ? null : target.closest('#imageLightboxDownload');
        if (!imgBtn && !lightboxBtn) return;

        event.preventDefault();
        event.stopPropagation();
        const img = imgBtn
            ? (imgBtn.parentNode ? imgBtn.parentNode.querySelector('img') : null)
            : document.getElementById('imageLightboxImg');
        if (img && img.getAttribute('src')) downloadImage(img.currentSrc || img.src, imgBtn || lightboxBtn);
    }, true);

    document.addEventListener('click', function (event) {
        const target = event.target;
        if (!target || !target.closest) return;

        const item = target.closest('.file-menu-item');
        if (item) {
            const card = item.closest('.file-card');
            const ext = item.dataset.ext || '';
            if (card) {
                downloadCard(card, ext);
            } else {
                const menu = item.closest('.file-menu');
                const opener = menu && menu.__opener;
                closeMenus();
                if (opener) downloadCode(opener, ext);
            }
            return;
        }

        // Fenêtre « Version soignée » : dans la carte (ordinateur) ou en feuille
        // (téléphone). Un clic ailleurs dans la fenêtre (cases, libellés) ne
        // déclenche aucune action de la carte.
        const panel = target.closest('.file-polish-panel');
        if (panel) {
            if (target.closest('.file-polish-close, .file-polish-cancel')) {
                const opener = panel.__button;
                closePolishPanel(panel);
                if (opener && document.contains(opener)) opener.focus();
                return;
            }
            if (target.closest('.file-polish-retry')) {
                renderPanel(panel, null, '');
                loadPanel(panel);
                return;
            }
            if (target.closest('.file-polish-launch')) { launchFromPanel(panel); return; }
            const change = target.closest('.file-polish-change');
            if (change) {
                const list = panel.querySelector('.file-polish-models');
                if (list) {
                    list.hidden = !list.hidden;
                    change.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
                }
            }
            return;
        }

        const polishItem = target.closest('.file-polish-item');
        if (polishItem) {
            const owner = polishItem.closest('.file-card');
            if (owner) choosePolish(owner, polishItem);
            return;
        }

        const card = target.closest('.file-card');
        if (card) {
            const dl = target.closest('.file-download');
            if (dl) { downloadCard(card, dl.dataset.ext || readCard(card).ext); return; }

            const more = target.closest('.file-more');
            if (more) {
                const menu = card.querySelector('.file-menu');
                if (menu) { menu.hidden ? openMenu(more, menu) : closeMenus(); }
                return;
            }

            const copy = target.closest('.file-card-copy');
            if (copy) { copySource(copy, readCard(card).source); return; }

            const editBtn = target.closest('.file-edit-deck');
            if (editBtn) { editDeck(card, editBtn); return; }

            const polish = target.closest('.file-polish');
            if (polish) { openPolish(card, polish); return; }

            const toggle = target.closest('.file-card-toggle');
            if (toggle) {
                const preview = card.querySelector('.file-card-preview');
                if (preview) {
                    const clamped = preview.classList.toggle('is-clamped');
                    toggle.setAttribute('aria-expanded', clamped ? 'false' : 'true');
                    toggle.textContent = clamped ? t('Afficher tout') : t('Réduire');
                }
                return;
            }

            const retry = target.closest('.file-retry, .file-alt');
            if (retry) { downloadCard(card, retry.dataset.ext || readCard(card).ext); return; }

            const raw = target.closest('.file-raw');
            if (raw) {
                saveRaw(readCard(card));
                clearStatus(card);
                return;
            }
        }

        const codeBtn = target.closest('.btn-download-code');
        if (codeBtn) {
            const file = codeFile(codeBtn);
            if (file.targets.length <= 1) {
                downloadCode(codeBtn, file.ext);
                return;
            }
            // Plusieurs formats (un bloc ```csv donne aussi un classeur) : menu.
            const open = document.querySelector('.file-menu.is-floating');
            const mine = !!open && open.__opener === codeBtn;
            closeMenus();
            if (!mine) openCodeMenu(codeBtn, file);
            return;
        }

        if (!target.closest('.file-menu, .file-polish-menu')) closeMenus();
    });

    // Le modèle coché change : éditeur, estimation et bouton de la fenêtre suivent.
    document.addEventListener('change', function (event) {
        const input = event.target && event.target.closest ? event.target.closest('input[data-polish-model]') : null;
        const panel = input ? input.closest('.file-polish-panel') : null;
        if (panel) {
            panelError(panel, '');
            syncPanel(panel);
        }
    });

    // Fenêtre « Version soignée » : Échap la referme, Entrée dans la précision lance.
    document.addEventListener('keydown', function (event) {
        if (!activePanel) return;
        const panel = activePanel;
        if (event.key === 'Enter' && !event.isComposing && event.target && event.target.classList
            && event.target.classList.contains('file-polish-note') && panel.contains(event.target)) {
            event.preventDefault();
            launchFromPanel(panel);
            return;
        }
        if (event.key !== 'Escape' || document.querySelector('.file-menu:not([hidden]), .file-polish-menu:not([hidden])')) return;
        if (!panel.classList.contains('is-sheet') && !panel.contains(document.activeElement)) return;
        const opener = panel.__button;
        closePolishPanel(panel);
        if (opener && document.contains(opener)) opener.focus();
        event.stopPropagation();
    });

    document.addEventListener('keydown', function (event) {
        const menu = document.querySelector('.file-menu:not([hidden]), .file-polish-menu:not([hidden])');
        if (!menu) return;

        if (event.key === 'Escape') {
            const opener = openerOf(menu);
            closeMenus();
            if (opener) opener.focus();
            event.stopPropagation();
            return;
        }
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].indexOf(event.key) === -1) return;

        const items = Array.prototype.slice.call(menu.querySelectorAll('.file-menu-item, .file-polish-item'));
        if (!items.length) return;
        const index = items.indexOf(document.activeElement);
        let next = 0;
        if (event.key === 'ArrowDown') next = (index + 1) % items.length;
        else if (event.key === 'ArrowUp') next = (index - 1 + items.length) % items.length;
        else if (event.key === 'End') next = items.length - 1;
        items[next].focus();
        event.preventDefault();
    });

    // Un menu flottant est placé aux coordonnées de son bouton : dès que la page
    // défile ou change de taille, ces coordonnées ne désignent plus rien.
    const closeFloating = function () {
        if (document.querySelector('.file-menu.is-floating')) closeMenus();
    };
    document.addEventListener('scroll', closeFloating, true);
    root.addEventListener('resize', closeFloating);

    root.MiaFiles = Object.assign(api, {
        hydrate: hydrate,
        flattenForExport: flattenForExport,
        codeDownloadHtml: codeDownloadHtml,
        downloadImage: downloadImage,
        saveBlob: saveBlob,
        cardHtml: cardHtml,
        refreshNative: refreshNative,
        // Rendu en cours d'un APERÇU de document : un bloc spécial (fichier,
        // choix en boutons) n'y est que du contenu — choice-buttons.js le lit.
        isPreviewing: function () { return previewDepth > 0; },
    });
})(typeof window !== 'undefined' ? window : globalThis);
