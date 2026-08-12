#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * BONSAI CLI
 *
 *   php cli.php "Given two binary strings a and b, return their sum as a binary string"
 *   php cli.php --session <id> "continue from here"
 */

$config = require __DIR__ . '/bootstrap.php';

$args = array_slice($argv, 1);
$sessionId = null;
$promptParts = [];
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--session' && isset($args[$i + 1])) {
        $sessionId = $args[++$i];
        continue;
    }
    $promptParts[] = $args[$i];
}
$prompt = trim(implode(' ', $promptParts));
if ($prompt === '' || $prompt === '-') {
    $stdin = stream_get_contents(STDIN);
    $prompt = trim((string) $stdin);
}
if ($prompt === '') {
    $prompt = 'Given two binary strings `a` and `b`, return their sum as a binary string.';
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

fwrite(STDERR, "BONSAI  ·  {$config['model']}\n");
fwrite(STDERR, str_repeat('─', 56) . "\n");

$emit = static function (array $event): void {
    $type = $event['type'] ?? '';
    match ($type) {
        'session' => fwrite(STDERR, "session {$event['id']}\n"),
        'thinking' => fwrite(STDERR, $event['delta'] ?? ''),
        'content' => fwrite(STDOUT, $event['delta'] ?? ''),
        'tool_start' => fwrite(STDERR, "\n▸ {$event['name']} " . json_encode($event['args'] ?? [], JSON_UNESCAPED_UNICODE) . "\n"),
        'tool_end' => fwrite(STDERR, ($event['ok'] ? '✓' : '✗') . " " . substr((string) ($event['output'] ?? ''), 0, 400) . "\n"),
        'error' => fwrite(STDERR, "\nERROR: {$event['message']}\n"),
        'done' => fwrite(STDERR, "\n" . str_repeat('─', 56) . "\n"),
        default => null,
    };
};

try {
    $result = $agent->turn($prompt, $sessionId, $emit, true);
    if (($result['content'] ?? '') !== '' && !str_contains((string) stream_get_contents(fopen('php://stdout', 'r')), $result['content'])) {
        // content already streamed
    }
    fwrite(STDERR, "tokens {$result['usage']['total_tokens']}  steps {$result['steps']}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
