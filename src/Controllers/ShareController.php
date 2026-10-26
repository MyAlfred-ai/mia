<?php

declare(strict_types=1);

/**
 * Partage d'une création par lien unique (/share/{token}).
 *
 * Le propriétaire d'une conversation peut partager une création générée
 * (page HTML, image, vidéo, carte) via une URL non devinable. La page de
 * partage est réservée aux utilisateurs CONNECTÉS et n'expose JAMAIS la
 * conversation : uniquement la création, le nom du partageur et la date.
 */
class ShareController
{
    /** POST /chat/message/{id}/share — crée (ou régénère) le lien de partage. */
    public function create(string $id): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();
        $user = Session::currentUser();

        $msg = Message::findById((int)$id);
        if (!$msg) {
            $this->json(['error' => 'Message introuvable'], 404);
            return;
        }

        if (!Conversation::belongsToUser((int)$msg['conversation_id'], $user['id'])) {
            $this->json(['error' => 'Accès refusé'], 403);
            return;
        }

        if (Message::creationType($msg) === null) {
            $this->json(['error' => 'Ce message ne contient aucune création partageable.'], 422);
            return;
        }

        $share = !empty($_POST['regenerate'])
            ? CreationShare::regenerate((int)$msg['id'], (int)$user['id'])
            : CreationShare::createOrGet((int)$msg['id'], (int)$user['id']);

        $this->json([
            'ok'     => true,
            'shared' => true,
            'token'  => (string)$share['token'],
            'url'    => '/share/' . $share['token'],
        ]);
    }

    /** POST /chat/message/{id}/unshare — révoque le lien (l'URL devient 404). */
    public function revoke(string $id): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();
        $user = Session::currentUser();

        $msg = Message::findById((int)$id);
        if (!$msg) {
            $this->json(['error' => 'Message introuvable'], 404);
            return;
        }

        if (!Conversation::belongsToUser((int)$msg['conversation_id'], $user['id'])) {
            $this->json(['error' => 'Accès refusé'], 403);
            return;
        }

        CreationShare::revoke((int)$msg['id']);
        $this->json(['ok' => true, 'shared' => false]);
    }

    /**
     * GET /share/{token} — affiche la création partagée (utilisateurs connectés).
     * Toute anomalie (token forgé, révoqué, création illisible) → 404 identique :
     * aucun oracle sur l'existence d'un partage.
     */
    public function show(string $token): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->notFound();
            return;
        }

        $share = CreationShare::findByToken($token);
        if (!$share) {
            $this->notFound();
            return;
        }

        $creationType = Message::creationType($share);
        if ($creationType === null) {
            $this->notFound();
            return;
        }

        // Payload par type — seul le strict nécessaire descend au template.
        $shareHtml  = null;
        $shareMap   = null;
        $shareMedia = null;

        switch ($creationType) {
            case 'html_page':
                // Rendu dans une iframe blob: (origine opaque, aucun cookie) →
                // images générées en URL signée, sinon MediaController les refuse.
                $shareHtml = MediaUrl::signHtmlAssets(
                    GeneratedHtml::sanitize((string)($share['content'] ?? ''))
                );
                break;

            case 'map_route':
                $spec = json_decode((string)($share['content'] ?? ''), true);
                if (!is_array($spec) || empty($spec['waypoints'])) {
                    $this->notFound();
                    return;
                }
                $shareMap = $spec;
                break;

            case 'slide_deck':
                // Le deck est rendu côté serveur (DeckRenderer, source unique)
                // puis servi comme une page HTML : la page de partage n'a
                // aucune logique de diaporama à dupliquer.
                $deck = DeckSpec::decode((string)($share['content'] ?? ''));
                if ($deck === null || $deck['slides'] === []) {
                    $this->notFound();
                    return;
                }
                // Iframe blob: (origine opaque, aucun cookie) → images signées.
                $shareHtml = MediaUrl::signHtmlAssets(DeckRenderer::render($deck));
                break;

            case 'video_file':
                $files = json_decode((string)($share['files_json'] ?? ''), true) ?: [];
                foreach ($files as $file) {
                    if (($file['type'] ?? '') === 'video_file' && !empty($file['path'])) {
                        $shareMedia = [
                            'url'  => (string)$file['path'],
                            'name' => (string)($file['name'] ?? basename((string)$file['path'])),
                        ];
                        break;
                    }
                }
                if ($shareMedia === null) {
                    $this->notFound();
                    return;
                }
                break;

            case 'generated_image':
                $imageUrl = Message::generatedImageUrl($share);
                if ($imageUrl === null) {
                    $this->notFound();
                    return;
                }
                $shareMedia = ['url' => $imageUrl, 'name' => basename($imageUrl)];
                break;
        }

        $shareInfo = [
            'type'      => $creationType,
            'shared_by' => (string)($share['shared_by'] ?? ''),
            'shared_at' => (string)($share['shared_at'] ?? ''),
        ];

        require ROOT . '/templates/share/show.php';
    }

    private function notFound(): void
    {
        http_response_code(404);
        require ROOT . '/templates/404.php';
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
