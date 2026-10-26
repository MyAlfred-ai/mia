<?php

declare(strict_types=1);

/**
 * Pièces jointes d'un email que MIA envoie ou dépose en brouillon — pur.
 *
 * Usages : la livraison d'un workflow (« envoyer le livrable par email »),
 * qui joint les pièces du dossier fabriquées en fichiers (FileFactory), et
 * l'email écrit par une étape de workflow, dont les images du dossier citées
 * par un repère sont INTÉGRÉES au corps (`cid`, WorkflowInserts). Jamais un
 * fichier désigné par le modèle : les arguments internes qui les portent
 * (`_wf_attach`) sont retirés de ce que le modèle propose
 * (WorkflowDeliveries::stripInternal) et posés par le serveur.
 *
 * Bornes : MAX_COUNT fichiers, MAX_BYTES au total — la limite la plus basse
 * des deux services (une pièce jointe Graph envoyée en une requête ne
 * dépasse pas 3 Mo ; au-delà il faudrait une session de téléversement).
 */
final class OutgoingAttachments
{
    public const MAX_COUNT = 5;
    public const MAX_BYTES = 3 * 1024 * 1024;

    /**
     * Pièces jointes nettoyées : nom sans caractère de contrôle ni chemin,
     * type MIME de forme valide (sinon application/octet-stream), octets non
     * vides. Lève si les bornes sont dépassées.
     *
     * @param list<array{name: string, mime: string, bytes: string}> $list
     * @return list<array{name: string, mime: string, bytes: string}>
     */
    public static function normalize(array $list): array
    {
        $out = [];
        $total = 0;
        foreach ($list as $a) {
            if (!is_array($a) || !is_string($a['bytes'] ?? null) || $a['bytes'] === '') {
                continue;
            }
            $item = [
                'name'  => self::cleanName((string)($a['name'] ?? '')),
                'mime'  => self::cleanMime((string)($a['mime'] ?? '')),
                'bytes' => $a['bytes'],
            ];
            // Image INTÉGRÉE au corps (repère posé par le serveur, cf.
            // MailHtml::IMAGE_MARKER_PATTERN) : seulement une image, `cid` de
            // la forme attendue — sinon une pièce jointe ordinaire.
            $cid = (string)($a['cid'] ?? '');
            if ($cid !== '' && MailHtml::isImageCid($cid) && str_starts_with($item['mime'], 'image/')) {
                $item['cid'] = $cid;
            }
            $out[] = $item;
            $total += strlen($a['bytes']);
        }
        if (count($out) > self::MAX_COUNT) {
            throw new \RuntimeException(t('{n} pièces jointes au plus par email.', ['n' => self::MAX_COUNT]));
        }
        if ($total > self::MAX_BYTES) {
            throw new \RuntimeException(t('Pièces jointes trop lourdes : {n} Mo au plus par email.', ['n' => (int)(self::MAX_BYTES / 1024 / 1024)]));
        }
        return $out;
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string)preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name);
        $name = trim($name);
        return $name !== '' ? mb_substr($name, 0, 150) : 'piece-jointe';
    }

    public static function cleanMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        return preg_match('~^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$~', $mime) ? $mime : 'application/octet-stream';
    }

    /**
     * Partie MIME d'une pièce jointe (base64, nom en RFC 2231 et repli
     * ASCII). Une image intégrée (`cid`) porte son `Content-ID` et
     * `Content-Disposition: inline` : le corps HTML l'affiche par `cid:`.
     */
    public static function mimePart(array $a, string $boundary): string
    {
        $ascii = (string)preg_replace('/[^A-Za-z0-9._ -]+/', '_', $a['name']);
        $encoded = "UTF-8''" . rawurlencode($a['name']);
        $inline = isset($a['cid']) && MailHtml::isImageCid((string)$a['cid']);
        return '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $a['mime'] . '; name="' . $ascii . "\"\r\n"
            . ($inline ? 'Content-ID: <' . $a['cid'] . ">\r\n" : '')
            . 'Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=' . $encoded . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($a['bytes']), 76, "\r\n")) . "\r\n";
    }

    /** Les images intégrées d'une liste normalisée, pour MailHtml::withInlineImages. @return list<array{cid: string, name: string}> */
    public static function inlineImages(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $a) {
            if (isset($a['cid']) && MailHtml::isImageCid((string)$a['cid'])) {
                $out[] = ['cid' => (string)$a['cid'], 'name' => pathinfo((string)($a['name'] ?? ''), PATHINFO_FILENAME)];
            }
        }
        return $out;
    }

    /** Corps d'une pièce jointe Graph (`fileAttachment`). */
    public static function graphPayload(array $a): array
    {
        $payload = [
            '@odata.type'  => '#microsoft.graph.fileAttachment',
            'name'         => $a['name'],
            'contentType'  => $a['mime'],
            'contentBytes' => base64_encode($a['bytes']),
        ];
        // Image intégrée : affichée dans le corps par `cid:` (Graph : isInline + contentId).
        if (isset($a['cid']) && MailHtml::isImageCid((string)$a['cid'])) {
            $payload['isInline'] = true;
            $payload['contentId'] = (string)$a['cid'];
        }
        return $payload;
    }
}
