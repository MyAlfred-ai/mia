<?php

declare(strict_types=1);

/**
 * Vocabulaire du journal serveur (Maintenance → Diagnostic → Journaux) —
 * SOURCE UNIQUE des gravités, des domaines et de la signature d'une entrée.
 *
 * Le journal mêle trois écritures : les lignes de l'application
 * (`[Étiquette] message`), celles d'ErrorHandler (`[incident xxxxxxxxxxxx]
 * Classe: message @ fichier:ligne`, `[Avertissement] … @ fichier:ligne`) et
 * celles de PHP lui-même (`PHP Warning:  …`). L'ancien classement ne
 * reconnaissait que la troisième : les incidents et les avertissements
 * d'ErrorHandler — l'essentiel des pannes réelles — tombaient dans « Autres ».
 *
 * Tout est PUR : aucune lecture de fichier, aucun réglage, testable sans base.
 */
final class LogCatalog
{
    /** Gravités, de la plus grave à la plus anodine (l'ordre est un rang). */
    public const LEVELS = [
        'critical' => ['label' => 'Critiques',      'one' => 'Critique',      'rank' => 3],
        'error'    => ['label' => 'Erreurs',        'one' => 'Erreur',        'rank' => 2],
        'warning'  => ['label' => 'Avertissements', 'one' => 'Avertissement', 'rank' => 1],
        'info'     => ['label' => 'Infos',          'one' => 'Info',          'rank' => 0],
    ];

    /** Ce qu'on regarde d'office : les infos (connexions, démarrages) sont du bruit. */
    public const DEFAULT_LEVELS = ['critical', 'error', 'warning'];

    /**
     * Domaines de l'application, dans l'ordre d'affichage. `tags` = motif sur
     * l'étiquette entre crochets ; `paths` = dossiers de src/ qui rattachent un
     * incident (qui n'a pas d'étiquette, seulement l'emplacement de l'exception).
     */
    public const SOURCES = [
        'ia' => [
            'label' => 'IA & modèles',
            'tags'  => '/^(?:[A-Za-z]*Adapter(?:\/.*)?|OpenAI.*|Anthropic.*|Mistral.*|Gemini.*|DeepSeek.*|Perplexity.*|Moonshot.*|Zai.*|LlmAdapterHelpers|LLM.*|Incident fournisseur|ConvTitle|Condensation|Contexte|Estimation|Suggestion|Mémoire|speech|voix|realtimeTranscriptionToken|Prompt assistant|ChatController|Conversations|Télémétrie agent|assistants)$/iu',
            'paths' => ['src/LLM/', 'src/Agent/', 'src/Assistants/', 'src/Controllers/ChatController.php'],
        ],
        'creations' => [
            'label' => 'Créations & fichiers',
            'tags'  => '/^(?:ImageGenerator|VideoGenerator|HtmlIllustrator|Image|Deck|PptxTemplate|DocxTemplate|DocxExport|DOCX Export|PDF Export|Fichiers|Fichier par le modèle|Références image|gamma|Geocoder|Brouillon)$/iu',
            'paths' => ['src/Slides/', 'src/Files/', 'src/Helpers/ImageGenerator.php', 'src/Helpers/VideoGenerator.php', 'src/Helpers/HtmlIllustrator.php', 'src/Jobs/HtmlPageJob.php'],
        ],
        'kb' => [
            'label' => 'Bases & RAG',
            'tags'  => '/^(?:KB.*|RAG|DefaultAssistant|MyBrain.*|Qdrant.*|FileExtractor|Embedding.*|Recherche multi-sources)$/iu',
            'paths' => ['src/RAG/', 'src/Sync/', 'src/MyBrain/', 'src/Models/KnowledgeBase', 'src/Helpers/FileExtractor.php', 'src/Helpers/DefaultAssistant.php'],
        ],
        'connecteurs' => [
            'label' => 'Connecteurs',
            'tags'  => '/^(?:connecteurs|.+ user|.+ OAuth|Microsoft OAuth|Slack.*|Teams.*|n8n|approbation.*|Tâches règles)$/iu',
            'paths' => ['src/Connectors/', 'src/Drive/', 'src/Gmail/', 'src/Outlook/', 'src/Calendar/', 'src/Tasks/', 'src/Todo/', 'src/Slack/', 'src/Teams/', 'src/N8n/', 'src/Gamma/'],
        ],
        'automatisation' => [
            'label' => 'Tâches & workflows',
            'tags'  => '/^(?:Tâches programmées|Workflows?.*|Scheduler|Push)$/iu',
            'paths' => ['src/Workflow/', 'src/Schedule/', 'src/Push/'],
        ],
        'jobs' => [
            'label' => 'File & workers',
            'tags'  => '/^(?:JobQueue|worker|ToolTestRun|Jobs?.*)$/iu',
            'paths' => ['src/Jobs/', 'scripts/worker.php', 'scripts/run_job.php'],
        ],
        'securite' => [
            'label' => 'Connexion & sécurité',
            'tags'  => '/^(?:AUTH|CSRF|SSO.*|Rôles|Contacts administrateurs|Media)$/iu',
            'paths' => ['src/Auth/', 'src/Security/', 'src/Middleware/'],
        ],
        'donnees' => [
            'label' => 'Base & stockage',
            'tags'  => '/^(?:db|migrate|Backup|Élagage|AvatarStorage|Marque|Settings|entrypoint)$/iu',
            'paths' => ['src/Backup/', 'src/Maintenance/', 'src/Models/Database.php', 'src/Helpers/AvatarStorage.php', 'database/'],
        ],
        'couts' => [
            'label' => 'Coûts & usage',
            'tags'  => '/^(?:Usage.*|Budget.*|Points à régler|AdminController)$/iu',
            'paths' => ['src/Billing/'],
        ],
        'php' => [
            'label' => 'PHP',
            'tags'  => '/^(?:PHP .*|Avertissement|Remarque|Obsolète|Erreur.*|I18n)$/iu',
            'paths' => [],
        ],
        'other' => [
            'label' => 'Autres',
            'tags'  => null,
            'paths' => [],
        ],
    ];

    /** Étiquettes d'ErrorHandler et de PHP : la gravité y est écrite en toutes lettres. */
    private const PHP_LEVELS = [
        'critical' => '/^(?:Erreur fatale|Erreur d\'analyse|PHP Fatal error|PHP Parse error)$/iu',
        'error'    => '/^(?:Erreur récupérable|Erreur|PHP Recoverable fatal error)$/iu',
        'warning'  => '/^(?:Avertissement|Remarque|Obsolète|PHP Warning|PHP Notice|PHP Deprecated)$/iu',
    ];

    /** Exceptions qui arrêtent toute l'application, pas une seule demande. */
    private const CRITICAL_CLASSES = '/^(?:DatabaseUnavailableException|MigrationFailedException|ParseError|CompileError)$/';

    /** Mots qui disent qu'une opération n'a PAS abouti. */
    private const ERROR_WORDS = '/(?:\b(?:échec|échoué|échouée|échoués|échouées|erreur|error|exception|failed|failure|impossible|injoignable|unreachable|sqlstate|fatal|introuvable|timeout|timed out|gone away|crash|aucune résolution|invalid|invalide|denied|unauthorized|forbidden)\b|\bHTTP\s*[45]\d\d\b|\b[45]\d\d\s*[—–]\s|\bstatut\s*[45]\d\d\b|\(\s*[45]\d\d\s*\))/iu';

    /** Mots qui disent qu'elle a abouti autrement que prévu (repli, refus, rejet…). */
    private const WARNING_WORDS = '/(?:\b(?:indisponible|ignoré|ignorée|périmé|périmée|obsolète|dégradé|dégradée|repli|fallback|bascule|rejet|rejeté|rejetée|refus|refusé|refusée|tronqué|tronquée|retry|abandon|abandonné|incomplète|incomplet|lent|lente|expiré|expirée|révoqué|révoquée|warning|deprecated|non tarifé|non tarifée|orphelin|orphelins|repris|manqué|manquée|dépassé|dépassée|alerte)\b|⚠|≠)/iu';

    /** Étiquettes dont un échec relève de l'utilisateur (mot de passe faux, jeton périmé) : jamais une erreur. */
    private const USER_FACING_TAGS = '/^(?:AUTH|CSRF)$/i';

    public static function isLevel(string $level): bool
    {
        return isset(self::LEVELS[$level]);
    }

    public static function isSource(string $source): bool
    {
        return isset(self::SOURCES[$source]);
    }

    /**
     * Libellé AFFICHÉ d'une gravité, traduit. Les constantes ne peuvent pas
     * appeler t() : leur texte français est la clé, traduite ici — l'accesseur
     * est le seul chemin vers l'écran.
     */
    public static function levelLabel(string $level, bool $one = false): string
    {
        $label = self::LEVELS[$level][$one ? 'one' : 'label'] ?? null;
        return $label !== null ? t($label) : $level;
    }

    /** Libellé AFFICHÉ d'un domaine, traduit (même règle que levelLabel()). */
    public static function sourceLabel(string $source): string
    {
        $label = self::SOURCES[$source]['label'] ?? null;
        return $label !== null ? t($label) : $source;
    }

    public static function rank(string $level): int
    {
        return self::LEVELS[$level]['rank'] ?? 0;
    }

    /**
     * Gravité d'une entrée.
     *
     * @param string|null $tag       étiquette (« JobQueue », « Avertissement », « PHP Warning », nom d'exception)
     * @param string      $message   première ligne, sans horodatage ni étiquette
     * @param bool        $incident  ligne d'ErrorHandler portant un identifiant d'incident
     */
    public static function levelOf(?string $tag, string $message, bool $incident = false): string
    {
        $tag = $tag !== null ? trim($tag) : '';

        foreach (self::PHP_LEVELS as $level => $pattern) {
            if ($tag !== '' && preg_match($pattern, $tag) === 1) {
                return $level;
            }
        }
        if (preg_match('/Allowed memory size|Out of memory|Maximum execution time|Failed opening required/i', $message) === 1) {
            // Mémoire épuisée, délai dépassé, fichier de classe absent : la demande
            // — voire toute l'application — tombe, quelle que soit l'étiquette.
            return 'critical';
        }
        if ($incident) {
            // Une exception non rattrapée a interrompu une demande.
            return preg_match(self::CRITICAL_CLASSES, $tag) === 1 ? 'critical' : 'error';
        }
        if (preg_match('/^migrate$/i', $tag) === 1 && preg_match(self::ERROR_WORDS, $message) === 1) {
            // Une migration en échec laisse le schéma à moitié appliqué.
            return 'critical';
        }
        if ($tag !== '' && preg_match(self::USER_FACING_TAGS, $tag) === 1) {
            return preg_match(self::ERROR_WORDS, $message) === 1 || preg_match(self::WARNING_WORDS, $message) === 1
                ? 'warning'
                : 'info';
        }
        if (preg_match(self::ERROR_WORDS, $message) === 1) {
            return 'error';
        }
        if (preg_match(self::WARNING_WORDS, $message) === 1) {
            return 'warning';
        }
        return 'info';
    }

    /**
     * Domaine d'une entrée : par son étiquette, sinon par l'emplacement de
     * l'exception (incidents), sinon « PHP » pour une ligne native, sinon « Autres ».
     */
    public static function sourceOf(?string $tag, ?string $location): string
    {
        $tag = $tag !== null ? trim($tag) : '';
        if ($tag !== '') {
            foreach (self::SOURCES as $key => $def) {
                if ($def['tags'] !== null && preg_match($def['tags'], $tag) === 1) {
                    return $key;
                }
            }
        }
        if ($location !== null && $location !== '') {
            foreach (self::SOURCES as $key => $def) {
                foreach ($def['paths'] as $prefix) {
                    if (str_starts_with($location, $prefix)) {
                        return $key;
                    }
                }
            }
            return 'php';
        }
        return 'other';
    }

    /**
     * Forme stable d'un message : ce qui varie d'une occurrence à l'autre
     * (nombres, identifiants, adresses, horodatages) est remplacé, ce qui
     * désigne la panne (texte, chemins, noms de classes) reste. Deux messages
     * de même forme sont le même problème.
     */
    public static function normalize(string $message): string
    {
        $m = $message;
        $m = preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', '‹email›', $m) ?? $m;
        $m = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '‹id›', $m) ?? $m;
        $m = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '‹ip›', $m) ?? $m;
        $m = preg_replace('/\b[0-9a-f]{8,}\b/i', '‹id›', $m) ?? $m;
        // Jetons longs (sessions, clés de cache, jetons d'accès) : jamais deux fois les mêmes.
        $m = preg_replace('/\b(?=[A-Za-z0-9_-]*\d)[A-Za-z0-9_-]{20,}\b/', '‹id›', $m) ?? $m;
        $m = preg_replace('/\d+(?:[.,]\d+)*/', '#', $m) ?? $m;
        $m = preg_replace('/\s+/u', ' ', $m) ?? $m;
        $m = trim($m);
        return mb_substr($m, 0, 240);
    }

    /** Signature courte d'un problème (10 caractères hexadécimaux). */
    public static function fingerprint(?string $tag, string $message): string
    {
        return substr(sha1(mb_strtolower(trim((string)$tag)) . '|' . self::normalize($message)), 0, 10);
    }

    public static function isFingerprint(string $value): bool
    {
        return preg_match('/^[0-9a-f]{10}$/', $value) === 1;
    }

    public static function isIncidentId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{12}$/', $value) === 1;
    }
}
