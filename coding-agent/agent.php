<?php

declare(strict_types=1);

/**
 * BONSAI front controller — web UI + JSON/SSE API.
 *
 *   php -S 0.0.0.0:8080 agent.php
 */

$config = require __DIR__ . '/bootstrap.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$workspace = new Bonsai\Workspace($config['workspace']);
$sessions = new Bonsai\SessionStore($config['sessions']);
$tools = new Bonsai\ToolRegistry($workspace, (int) $config['run_timeout']);
$client = new Bonsai\TogetherClient(
    $config['api_key'],
    $config['api_base'],
    (int) $config['timeout'],
    (int) $config['connect_timeout'],
);
$agent = new Bonsai\CodingAgent($config, $client, $tools, $sessions, $workspace);

try {
    match (true) {
        $uri === '/' || $uri === '/index.html' => serveUi(),
        $uri === '/api/health' && $method === 'GET' => jsonOut([
            'ok' => true,
            'agent' => $config['agent_name'],
            'version' => $config['agent_version'],
            'model' => $config['model'],
            'php' => PHP_VERSION,
        ]),
        $uri === '/api/sessions' && $method === 'GET' => jsonOut(['sessions' => $sessions->list()]),
        $uri === '/api/sessions' && $method === 'POST' => jsonOut($sessions->create()),
        preg_match('#^/api/sessions/([a-f0-9]{16})$#', $uri, $m) === 1 && $method === 'GET'
            => jsonOut($sessions->get($m[1])),
        $uri === '/api/workspace' && $method === 'GET' => jsonOut([
            'root' => $workspace->root(),
            'entries' => $workspace->list('.', 5),
        ]),
        $uri === '/api/file' && $method === 'GET' => jsonOut([
            'path' => (string) ($_GET['path'] ?? ''),
            'content' => $workspace->read((string) ($_GET['path'] ?? '')),
        ]),
        $uri === '/api/chat' && $method === 'POST' => handleChat($agent),
        default => notFound(),
    };
} catch (Throwable $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
    jsonOut(['error' => $e->getMessage()]);
}

function serveUi(): void
{
    $file = __DIR__ . '/public/index.html';
    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    exit;
}

function handleChat(Bonsai\CodingAgent $agent): void
{
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        jsonOut(['error' => 'Invalid JSON body']);
    }
    $message = trim((string) ($body['message'] ?? ''));
    if ($message === '') {
        http_response_code(400);
        jsonOut(['error' => 'message is required']);
    }
    $sessionId = isset($body['session_id']) ? (string) $body['session_id'] : null;
    if ($sessionId === '') {
        $sessionId = null;
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    $emit = static function (array $event): void {
        echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        if (function_exists('flush')) {
            flush();
        }
    };

    try {
        $agent->turn($message, $sessionId, $emit, true);
    } catch (Throwable $e) {
        $emit(['type' => 'error', 'message' => $e->getMessage()]);
        $emit(['type' => 'done', 'content' => '']);
    }
    exit;
}

function jsonOut(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function notFound(): void
{
    http_response_code(404);
    jsonOut(['error' => 'Not found']);
}
