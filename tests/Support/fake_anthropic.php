<?php

declare(strict_types=1);

/**
 * Faux serveur de l'API Anthropic (routeur de `php -S`) pour le test de la
 * passerelle de Claude dans Office. Il note chaque requête reçue (en-têtes et
 * corps) dans le fichier FAKE_ANTHROPIC_LOG, puis répond selon le modèle
 * demandé :
 *   - `claude-credit-…` : 400, crédit épuisé (forme réelle d'Anthropic) ;
 *   - sinon, `"stream": true` : un flux SSE réaliste (recherche Internet
 *     comprise), envoyé en plusieurs morceaux ; sans flux : un message JSON.
 * `POST /v1/messages/count_tokens` rend un compte.
 */

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$body = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? (array)getallheaders() : [];
$log = getenv('FAKE_ANTHROPIC_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode(['path' => $path, 'headers' => array_change_key_case($headers), 'body' => $body]) . "\n", FILE_APPEND);
}
$req = json_decode($body, true);
$model = is_array($req) ? (string)($req['model'] ?? '') : '';

if ($path === '/v1/messages/count_tokens') {
    header('Content-Type: application/json');
    echo '{"input_tokens":42}';
    return;
}
if ($path !== '/v1/messages') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"type":"error","error":{"type":"not_found_error","message":"Not found"}}';
    return;
}
if (str_starts_with($model, 'claude-credit')) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo '{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits."}}';
    return;
}
if (!empty($req['stream'])) {
    header('Content-Type: text/event-stream');
    header('request-id: req_fake123');
    $events = [
        "event: message_start\ndata: " . json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_fake_01', 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'content' => [], 'usage' => ['input_tokens' => 1500, 'cache_read_input_tokens' => 500, 'cache_creation_input_tokens' => 0, 'output_tokens' => 1]]]) . "\n\n",
        "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n",
        "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"La somme de la colonne B vaut \"}}\n\n",
        "event: ping\ndata: {\"type\":\"ping\"}\n\n",
        "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"12 500 €.\"}}\n\n",
        "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n",
        "event: message_delta\ndata: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['input_tokens' => 2600, 'output_tokens' => 320, 'server_tool_use' => ['web_search_requests' => 1]]]) . "\n\n",
        "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n",
    ];
    foreach ($events as $e) {
        echo $e;
        @ob_flush();
        flush();
        usleep(20000);
    }
    return;
}
// Réglages qui changent le prix, rendus dans l'usage comme Anthropic le
// fait : écriture de cache d'une heure (`cache_control.ttl: "1h"` dans le
// système), mode rapide (`speed`), inférence aux États-Unis (`inference_geo`).
$usage = ['input_tokens' => 40, 'output_tokens' => 3];
$oneHour = is_array($req) && str_contains(json_encode($req['system'] ?? []), '"ttl":"1h"');
if ($oneHour) {
    $usage['cache_creation_input_tokens'] = 1000;
    $usage['cache_creation'] = ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 1000];
}
if (is_array($req) && ($req['speed'] ?? '') === 'fast') {
    $usage['speed'] = 'fast';
}
if (is_array($req) && ($req['inference_geo'] ?? '') === 'us') {
    $usage['inference_geo'] = 'us';
}
header('Content-Type: application/json');
echo json_encode(['id' => 'msg_fake_02', 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'stop_reason' => 'end_turn',
    'content' => [['type' => 'text', 'text' => 'OK']], 'usage' => $usage]);
