<?php

declare(strict_types=1);

/**
 * Agir sur chaque ligne d'une table de travail : UNE action par ligne (un
 * brouillon d'email, une tâche), décrite une fois par un GABARIT dont les
 * `{colonnes}` sont remplacées par les valeurs de la ligne.
 *
 * LE PROBLÈME RÉSOLU. « Prépare une relance pour chacun de ces 80 clients »
 * demandait au modèle 80 appels d'outil — il s'arrêtait au huitième, faute
 * d'allers-retours — et à la personne 80 cartes à approuver. Ici le modèle
 * écrit le gabarit, le SERVEUR le décline ligne par ligne par le handler même
 * de l'outil visé (mêmes validations qu'une action seule), chaque action est
 * tenue en réserve, et UNE carte les présente toutes : rien n'existe avant
 * l'accord. L'exécution suit en opération de fond, par le chemin d'un clic
 * « Approuver » pour chaque ligne, et note son issue dans la table.
 *
 * Outils permis : ceux qui CRÉENT sans écrire à personne — brouillons
 * d'email (jamais un envoi), tâches. Pas de message Slack ou Teams, pas
 * d'invitation : à cette échelle, une erreur ne se rattrape pas.
 *
 * Tout est pur ici (gabarit, colonnes de résultat, carte, messages) ; le
 * dépôt et l'exécution vivent dans ChatController et TableMissionJob.
 */
final class WorkTableActions
{
    /** Lignes au plus par action groupée. */
    public const ROWS_MAX = 200;
    /** Motifs d'écart cités au modèle et sur la carte. */
    public const REASONS_MAX = 3;
    /** Lignes listées dans l'aperçu de la carte. */
    public const PREVIEW_LINES = 200;

    public const STATUS_DONE   = 'fait';
    public const STATUS_FAILED = 'échec';

    /**
     * Outils permis → connecteur, et suffixe des colonnes de résultat
     * (`brouillon_statut`, `brouillon_lien`).
     */
    public const TOOLS = [
        'creer_brouillon_gmail'   => ['connector' => 'gmail',   'slug' => 'brouillon', 'mail' => true],
        'creer_brouillon_outlook' => ['connector' => 'outlook', 'slug' => 'brouillon', 'mail' => true],
        'creer_tache'             => ['connector' => 'tasks',   'slug' => 'tache',     'mail' => false],
        'creer_tache_todo'        => ['connector' => 'mstodo',  'slug' => 'tache',     'mail' => false],
    ];

    public static function isAllowed(string $tool): bool
    {
        return isset(self::TOOLS[$tool]);
    }

    /** « 12 brouillons Gmail », dans la langue de l'écran. */
    public static function countLabel(string $tool, int $n): string
    {
        return match ($tool) {
            'creer_brouillon_gmail'   => I18n::plural($n, '{n} brouillon Gmail', '{n} brouillons Gmail'),
            'creer_brouillon_outlook' => I18n::plural($n, '{n} brouillon Outlook', '{n} brouillons Outlook'),
            'creer_tache'             => I18n::plural($n, '{n} tâche Google', '{n} tâches Google'),
            'creer_tache_todo'        => I18n::plural($n, '{n} tâche Microsoft To Do', '{n} tâches Microsoft To Do'),
            default                   => I18n::plural($n, '{n} action', '{n} actions'),
        };
    }

    /**
     * Gabarit donné par le modèle : un objet, ou sa forme JSON en texte
     * (un paramètre `object` sans propriétés est refusé par certains
     * fournisseurs). Pure.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public static function template(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            throw new \InvalidArgumentException('`arguments` attend, en JSON, les arguments de l\'outil visé — les mêmes que pour une action seule — '
                . 'où chaque valeur propre à la ligne s\'écrit {colonne} : {"destinataire": "{email}", "objet": "Relance {numero}", "corps": "Bonjour {prenom}, …"}.');
        }
        return $raw;
    }

    /**
     * Colonnes citées par le gabarit, dans l'ordre de leur première apparition. Pure.
     *
     * @return list<string>
     */
    public static function placeholders(array $template): array
    {
        $found = [];
        array_walk_recursive($template, static function ($v) use (&$found): void {
            if (is_string($v) && preg_match_all('/\{([^{}\n]{1,60})\}/u', $v, $m)) {
                foreach ($m[1] as $name) {
                    $found[trim($name)] = true;
                }
            }
        });
        return array_keys($found);
    }

    /**
     * Chaque `{nom}` du gabarit → la clé de sa colonne. Pure.
     *
     * @return array<string, string>
     * @throws \InvalidArgumentException une colonne citée n'existe pas (les colonnes sont nommées)
     */
    public static function bind(array $columns, array $template): array
    {
        $map = [];
        foreach (self::placeholders($template) as $name) {
            $map[$name] = WorkTableOps::column($columns, $name);
        }
        if ($map === []) {
            throw new \InvalidArgumentException('Le gabarit ne cite aucune colonne : toutes les lignes donneraient la même action. '
                . 'Écris {colonne} là où la valeur change d\'une ligne à l\'autre. Colonnes de la table : ' . implode(', ', WorkTableOps::keys($columns)) . '.');
        }
        return $map;
    }

    /**
     * Arguments d'UNE ligne : le gabarit, ses `{colonnes}` remplacées. Pure.
     *
     * @param array<string, string> $map  nom cité → clé de colonne
     * @param array<string, string> $row
     */
    public static function fill(array $template, array $map, array $row): array
    {
        $pairs = [];
        foreach ($map as $name => $key) {
            $pairs['{' . $name . '}'] = (string)($row[$key] ?? '');
        }
        $walk = static function (mixed $v) use (&$walk, $pairs): mixed {
            if (is_string($v)) {
                return strtr($v, $pairs);
            }
            return is_array($v) ? array_map($walk, $v) : $v;
        };
        return array_map($walk, $template);
    }

    /**
     * Une ligne dont une colonne citée est vide ne donne pas d'action (un
     * brouillon sans destinataire, « Bonjour , »). Pure.
     *
     * @return string '' si la ligne se décline, sinon la première colonne vide
     */
    public static function emptyColumn(array $map, array $row): string
    {
        foreach ($map as $key) {
            if (trim((string)($row[$key] ?? '')) === '') {
                return (string)$key;
            }
        }
        return '';
    }

    /** @return array{status: string, link: string} clés des colonnes de résultat */
    public static function resultKeys(string $tool): array
    {
        $slug = self::TOOLS[$tool]['slug'] ?? 'action';
        return ['status' => $slug . '_statut', 'link' => $slug . '_lien'];
    }

    /** Colonnes de la table + les deux colonnes de résultat (retrouvées par leur clé, ajoutées sinon). Pure. */
    public static function withResultColumns(array $columns, string $tool): array
    {
        $keys = self::resultKeys($tool);
        $have = WorkTableOps::keys($columns);
        $label = match (self::TOOLS[$tool]['slug'] ?? '') {
            'brouillon' => ['Brouillon', 'Lien du brouillon'],
            'tache'     => ['Tâche', 'Lien de la tâche'],
            default     => ['Action', 'Lien'],
        };
        if (!in_array($keys['status'], $have, true)) {
            $columns[] = ['key' => $keys['status'], 'label' => $label[0], 'type' => 'text', 'tech' => false];
        }
        if (!in_array($keys['link'], $have, true)) {
            $columns[] = ['key' => $keys['link'], 'label' => $label[1], 'type' => 'text', 'tech' => false];
        }
        return array_values($columns);
    }

    /**
     * Rangs des lignes à traiter : celles que cette action n'a pas déjà
     * faites (une reprise ne crée pas deux brouillons). Pure.
     *
     * @return list<int>
     */
    public static function pending(array $rows, string $tool, bool $redo): array
    {
        $key = self::resultKeys($tool)['status'];
        $out = [];
        foreach (array_values($rows) as $position => $row) {
            if ($redo || trim((string)($row[$key] ?? '')) !== self::STATUS_DONE) {
                $out[] = $position;
            }
        }
        return $out;
    }

    /**
     * Carte d'approbation de l'action groupée. Pure, à la langue près.
     *
     * @param array                                              $table    table visée
     * @param list<array{pos: int, id: int, label: string}>      $items    actions tenues en réserve
     * @param list<array{pos: int, reason: string}>              $skipped  lignes écartées
     * @param string                                             $example  contenu de la première action (corps d'un email…)
     */
    public static function preview(string $tool, array $table, array $items, array $skipped, string $example = '', string $icon = ''): array
    {
        $n    = count($items);
        $mail = !empty(self::TOOLS[$tool]['mail']);
        $keys = self::resultKeys($tool);
        $impacts = [
            t('{actions} — une par ligne de la table « {table} ».', ['actions' => self::countLabel($tool, $n), 'table' => (string)$table['title']]),
        ];
        if ($skipped !== []) {
            $reasons = array_values(array_unique(array_map(static fn(array $s): string => $s['reason'], $skipped)));
            $impacts[] = I18n::plural(count($skipped), '{n} ligne écartée', '{n} lignes écartées') . ' : '
                . implode(' ; ', array_slice($reasons, 0, self::REASONS_MAX)) . (count($reasons) > self::REASONS_MAX ? '…' : '');
        }
        $impacts[] = $mail
            ? t('Rien n’est envoyé : chaque brouillon reste dans votre boîte, à relire et à envoyer vous-même.')
            : t('Chaque tâche est créée dans votre liste ; vous pourrez la modifier ou la supprimer ensuite.');
        $impacts[] = t('L’opération se poursuit en arrière-plan : vous pouvez continuer à travailler, ou l’arrêter depuis les générations en cours.');
        $impacts[] = t('Le résultat de chaque ligne est noté dans la table (colonnes « {a} » et « {b} »).', ['a' => $keys['status'], 'b' => $keys['link']]);

        $lines = [];
        foreach (array_slice($items, 0, self::PREVIEW_LINES) as $i => $item) {
            $lines[] = ($i + 1) . '. ' . trim((string)preg_replace('/\s+/u', ' ', $item['label']));
        }
        if ($n > self::PREVIEW_LINES) {
            $lines[] = '…';
        }
        $apercu = '';
        $example = trim($example);
        if ($example !== '') {
            $apercu = t('Exemple (première ligne) :') . "\n" . (mb_strlen($example) > 900 ? mb_substr($example, 0, 899) . '…' : $example) . "\n\n";
        }
        $apercu .= t('Toutes les lignes :') . "\n" . implode("\n", $lines);

        return [
            'action'       => t('Créer {actions}', ['actions' => self::countLabel($tool, $n)]),
            'fichier'      => t('Table « {table} »', ['table' => (string)$table['title']]),
            'impacts'      => $impacts,
            'apercu_titre' => t('Voir ce qui sera créé'),
            'apercu'       => $apercu,
        ] + ($icon !== '' ? ['icone' => $icon] : []);
    }

    /** Message posé dans la conversation à l'accord : l'opération part. */
    public static function startedMessage(string $tool, int $n, string $tableTitle): string
    {
        return '⏳ ' . t('Création de {actions} en cours, d’après la table « {table} ». Le bilan arrivera ici.', [
            'actions' => self::countLabel($tool, $n),
            'table'   => $tableTitle,
        ]);
    }

    /**
     * Bilan de l'opération. `stopped` : arrêtée avant la fin (le reste n'a
     * pas été créé). `changed` : arrêtée parce que la table a été modifiée
     * par un autre message pendant qu'elle tournait.
     */
    public static function resultMessage(string $tool, int $done, int $failed, int $left, string $tableName, bool $stopped, bool $changed = false): string
    {
        $keys = self::resultKeys($tool);
        $head = $done > 0
            ? '✅ ' . t('{actions} — table « {table} ».', ['actions' => self::doneLabel($tool, $done), 'table' => $tableName])
            : '⚠️ ' . t('Aucune action n’a abouti — table « {table} ».', ['table' => $tableName]);
        $parts = [$head];
        if ($failed > 0) {
            $parts[] = I18n::plural($failed, '{n} ligne en échec', '{n} lignes en échec') . ' '
                . t('(la raison est dans la colonne « {col} »).', ['col' => $keys['status']]);
        }
        if ($changed) {
            $parts[] = '⏹ ' . t('Opération arrêtée : la table a été modifiée pendant qu’elle tournait. Les derniers résultats n’ont pas pu y être notés.');
        }
        if ($stopped && $left > 0) {
            $parts[] = ($changed ? '' : '⏹ ' . t('Opération arrêtée :') . ' ') . I18n::plural($left, '{n} ligne n’a pas été traitée.', '{n} lignes n’ont pas été traitées.');
        }
        $parts[] = t('Chaque ligne porte son résultat (colonne « {a} ») et son lien (colonne « {b} ») : demandez l’export de la table pour tout voir.', [
            'a' => $keys['status'], 'b' => $keys['link'],
        ]);
        return implode(' ', $parts);
    }

    private static function doneLabel(string $tool, int $n): string
    {
        return match ($tool) {
            'creer_brouillon_gmail'   => I18n::plural($n, '{n} brouillon Gmail créé', '{n} brouillons Gmail créés'),
            'creer_brouillon_outlook' => I18n::plural($n, '{n} brouillon Outlook créé', '{n} brouillons Outlook créés'),
            'creer_tache'             => I18n::plural($n, '{n} tâche Google créée', '{n} tâches Google créées'),
            'creer_tache_todo'        => I18n::plural($n, '{n} tâche Microsoft To Do créée', '{n} tâches Microsoft To Do créées'),
            default                   => I18n::plural($n, '{n} action effectuée', '{n} actions effectuées'),
        };
    }
}
