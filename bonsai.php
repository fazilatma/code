<?php
/**
 * BONSAI — ultra-expert coding agent (single file)
 *
 * Together AI  ·  Prism-ML/Ternary-Bonsai-27B
 *
 * Web:  php -S 0.0.0.0:8080 bonsai.php
 * CLI:  php bonsai.php "Given two binary strings a and b, return their sum as a binary string"
 *
 * Tools: read_file, write_file, list_dir, search_code, run_code, run_tests, delete_file
 */
declare(strict_types=1);

const BONSAI_VERSION = '1.1.0';
const BONSAI_MODEL   = 'Prism-ML/Ternary-Bonsai-27B';
const BONSAI_API     = 'https://api.together.xyz/v1';
const BONSAI_KEY     = 'be7711af89dd9d10d1bcc10c3b64fc2fb0214953b6b29e9509a589d3ad015dba';

function cfg(string $k)
{
    static $c;
    if ($c === null) {
        $root = __DIR__;
        $c = [
            'api_key'          => getenv('TOGETHER_API_KEY') ?: BONSAI_KEY,
            'api_base'         => getenv('TOGETHER_API_BASE') ?: BONSAI_API,
            'model'            => getenv('TOGETHER_MODEL') ?: BONSAI_MODEL,
            'temperature'      => 0.3,
            'top_p'            => 0.95,
            'top_k'            => 20,
            'max_tokens'       => 8192,
            'max_steps'        => 18,
            'timeout'          => 180,
            'connect_timeout'  => 20,
            'run_timeout'      => 15,
            'workspace'        => $root . '/bonsai-workspace',
            'sessions'         => $root . '/bonsai-sessions',
        ];
        foreach (['workspace', 'sessions'] as $d) {
            if (!is_dir($c[$d])) {
                mkdir($c[$d], 0775, true);
            }
        }
    }
    return $c[$k];
}

function system_prompt(): string
{
    $ws = cfg('workspace');
    $model = cfg('model');
    $today = gmdate('Y-m-d');
    return <<<PROMPT
You are BONSAI, an ultra-expert coding agent (staff/principal engineer).
You run on {$model}. Today is {$today} UTC.

You do not guess. You design, implement, execute, and verify.

# Workspace
Sandbox: {$ws}
File tools are jailed there. Write solutions under clear names
(e.g. add_binary.php, test_add_binary.php).

# Protocol
1. Restate the contract: inputs, outputs, constraints, edge cases.
2. Choose an algorithm. State time and space complexity.
3. Write the implementation with a precise function signature.
4. Write a self-checking test harness (examples, zeros, unequal lengths, carry, fuzz).
5. run_code / run_tests. Never claim a pass you did not run.
6. On failure: diagnose, patch, re-run until green.
7. Final message: the function, complexity, and test evidence.

# Standards
- Default to PHP in this project; Python/JS are fine when asked.
- No invented sample runs. Use tools.
- Binary/bit problems: walk from the LSB with a carry. Do not BigInt the whole string.
- Paths are relative to the workspace. Tool arguments must be valid JSON.

# Voice
Concise. Technical. Lead with the result, then reasoning, then evidence.
PROMPT;
}

/* ───────────────────────────── Workspace ───────────────────────────── */

function ws_root(): string
{
    return cfg('workspace');
}

function ws_resolve(string $path, bool $mustExist = false): string
{
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    $root = ws_root();
    if ($path === '' || $path === '.') {
        return $root;
    }
    if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
        throw new InvalidArgumentException('Path traversal is not allowed');
    }
    $full = $root . '/' . $path;
    if ($mustExist) {
        $real = realpath($full);
        if ($real === false) {
            throw new InvalidArgumentException("Not found: {$path}");
        }
        ws_assert_inside($real);
        return $real;
    }
    $parent = dirname($full);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException("Cannot create directory: {$parent}");
    }
    $parentReal = realpath($parent);
    if ($parentReal === false) {
        throw new RuntimeException("Invalid parent for {$path}");
    }
    ws_assert_inside($parentReal);
    return $parentReal . '/' . basename($full);
}

function ws_assert_inside(string $abs): void
{
    $root = realpath(ws_root()) ?: ws_root();
    if ($abs !== $root && !str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) {
        throw new InvalidArgumentException('Path escapes the workspace');
    }
}

function ws_rel(string $abs): string
{
    $root = str_replace('\\', '/', realpath(ws_root()) ?: ws_root());
    $abs = str_replace('\\', '/', $abs);
    if (str_starts_with($abs, $root)) {
        $rel = ltrim(substr($abs, strlen($root)), '/');
        return $rel === '' ? '.' : $rel;
    }
    return $abs;
}

function ws_read(string $path): string
{
    $full = ws_resolve($path, true);
    if (!is_file($full)) {
        throw new InvalidArgumentException("Not a file: {$path}");
    }
    $data = file_get_contents($full);
    if ($data === false) {
        throw new RuntimeException("Failed to read {$path}");
    }
    return $data;
}

function ws_write(string $path, string $content): string
{
    $full = ws_resolve($path, false);
    if (file_put_contents($full, $content, LOCK_EX) === false) {
        throw new RuntimeException("Failed to write {$path}");
    }
    return ws_rel($full);
}

function ws_delete(string $path): void
{
    $full = ws_resolve($path, true);
    if (is_dir($full)) {
        throw new InvalidArgumentException('Refusing to delete a directory');
    }
    if (!unlink($full)) {
        throw new RuntimeException("Failed to delete {$path}");
    }
}

function ws_list(string $path = '.', int $depth = 4): array
{
    $full = ws_resolve($path === '' ? '.' : $path, true);
    $out = [];
    ws_walk($full, $out, 0, $depth);
    usort($out, static fn ($a, $b) => [$a['type'] !== 'dir', $a['path']] <=> [$b['type'] !== 'dir', $b['path']]);
    return $out;
}

function ws_walk(string $dir, array &$out, int $level, int $max): void
{
    if ($level > $max) {
        return;
    }
    foreach (@scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.gitkeep') {
            continue;
        }
        $full = $dir . '/' . $name;
        $isDir = is_dir($full);
        $out[] = [
            'path' => ws_rel($full),
            'type' => $isDir ? 'dir' : 'file',
            'size' => $isDir ? 0 : (int) filesize($full),
            'modified' => (int) filemtime($full),
        ];
        if ($isDir) {
            ws_walk($full, $out, $level + 1, $max);
        }
    }
}

function ws_search(string $query, string $path = '.', ?string $glob = null, int $limit = 80): array
{
    $full = ws_resolve($path === '' ? '.' : $path, true);
    $hits = [];
    ws_grep($full, $query, $glob, $hits, $limit);
    return $hits;
}

function ws_grep(string $dir, string $query, ?string $glob, array &$hits, int $limit): void
{
    if (count($hits) >= $limit) {
        return;
    }
    foreach (@scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = $dir . '/' . $name;
        if (is_dir($full)) {
            ws_grep($full, $query, $glob, $hits, $limit);
            continue;
        }
        if ($glob && !fnmatch($glob, $name)) {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['php', 'py', 'js', 'ts', 'json', 'md', 'txt', 'css', 'html', 'sh'], true)) {
            continue;
        }
        $fh = fopen($full, 'r');
        if ($fh === false) {
            continue;
        }
        $n = 0;
        while (($line = fgets($fh)) !== false) {
            $n++;
            if (stripos($line, $query) !== false) {
                $hits[] = ['path' => ws_rel($full), 'line' => $n, 'text' => rtrim($line, "\r\n")];
                if (count($hits) >= $limit) {
                    fclose($fh);
                    return;
                }
            }
        }
        fclose($fh);
    }
}

/* ───────────────────────────── Sessions ───────────────────────────── */

function session_path(string $id): string
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
        throw new RuntimeException('Invalid session id');
    }
    return cfg('sessions') . '/' . $id . '.json';
}

function session_create(?string $title = null): array
{
    $id = bin2hex(random_bytes(8));
    $now = time();
    $s = ['id' => $id, 'created' => $now, 'updated' => $now, 'title' => $title ?: 'Untitled session', 'messages' => []];
    session_save($s);
    return $s;
}

function session_get(string $id): array
{
    $file = session_path($id);
    if (!is_file($file)) {
        throw new RuntimeException("Session not found: {$id}");
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || empty($data['id'])) {
        throw new RuntimeException("Corrupt session: {$id}");
    }
    return $data;
}

function session_save(array $s): void
{
    $s['updated'] = time();
    if (($s['title'] ?? '') === 'Untitled session') {
        foreach ($s['messages'] as $m) {
            if (($m['role'] ?? '') === 'user' && is_string($m['content'] ?? null) && $m['content'] !== '') {
                $s['title'] = mb_substr($m['content'], 0, 72);
                break;
            }
        }
    }
    file_put_contents(session_path($s['id']), json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function session_list(): array
{
    $out = [];
    foreach (glob(cfg('sessions') . '/*.json') ?: [] as $file) {
        $d = json_decode((string) file_get_contents($file), true);
        if (!is_array($d) || empty($d['id'])) {
            continue;
        }
        $out[] = ['id' => $d['id'], 'title' => $d['title'] ?? '', 'updated' => (int) ($d['updated'] ?? 0), 'created' => (int) ($d['created'] ?? 0)];
    }
    usort($out, static fn ($a, $b) => $b['updated'] <=> $a['updated']);
    return $out;
}

/* ───────────────────────────── Tools ───────────────────────────── */

function tool_schemas(): array
{
    $fn = static function (string $name, string $desc, array $props, array $req): array {
        $schema = ['type' => 'object', 'properties' => $props, 'additionalProperties' => false];
        if ($req) {
            $schema['required'] = $req;
        }
        return ['type' => 'function', 'function' => ['name' => $name, 'description' => $desc, 'parameters' => $schema]];
    };
    return [
        $fn('read_file', 'Read a UTF-8 text file from the workspace.', [
            'path' => ['type' => 'string'],
        ], ['path']),
        $fn('write_file', 'Create or overwrite a text file in the workspace.', [
            'path' => ['type' => 'string'],
            'content' => ['type' => 'string'],
        ], ['path', 'content']),
        $fn('list_dir', 'List files and directories in the workspace.', [
            'path' => ['type' => 'string'],
            'depth' => ['type' => 'integer'],
        ], []),
        $fn('search_code', 'Search workspace text files for a substring.', [
            'query' => ['type' => 'string'],
            'path' => ['type' => 'string'],
            'glob' => ['type' => 'string'],
        ], ['query']),
        $fn('run_code', 'Execute PHP, Python, or Node.js. Provide file or inline code.', [
            'language' => ['type' => 'string', 'enum' => ['php', 'python', 'node', 'javascript']],
            'file' => ['type' => 'string'],
            'code' => ['type' => 'string'],
            'stdin' => ['type' => 'string'],
        ], ['language']),
        $fn('run_tests', 'Run a test file (php, python, node).', [
            'file' => ['type' => 'string'],
            'language' => ['type' => 'string'],
        ], ['file']),
        $fn('delete_file', 'Delete a single file from the workspace.', [
            'path' => ['type' => 'string'],
        ], ['path']),
    ];
}

function tool_exec(string $name, array $args): array
{
    try {
        [$output, $meta] = match ($name) {
            'read_file'   => tool_read($args),
            'write_file'  => tool_write($args),
            'list_dir'    => tool_list($args),
            'search_code' => tool_search($args),
            'run_code'    => tool_run($args, false),
            'run_tests'   => tool_tests($args),
            'delete_file' => tool_rm($args),
            default       => throw new InvalidArgumentException("Unknown tool: {$name}"),
        };
        return ['ok' => true, 'name' => $name, 'output' => $output, 'meta' => $meta];
    } catch (Throwable $e) {
        return ['ok' => false, 'name' => $name, 'output' => 'ERROR: ' . $e->getMessage(), 'meta' => ['error' => $e->getMessage()]];
    }
}

function tool_read(array $a): array
{
    $path = (string) ($a['path'] ?? '');
    $content = ws_read($path);
    if (strlen($content) > 120000) {
        $content = substr($content, 0, 120000) . "\n...[truncated]...";
    }
    return [$content, ['path' => $path, 'bytes' => strlen($content)]];
}

function tool_write(array $a): array
{
    $path = (string) ($a['path'] ?? '');
    $content = (string) ($a['content'] ?? '');
    $rel = ws_write($path, $content);
    $lines = substr_count($content, "\n") + ($content === '' ? 0 : 1);
    return ["Wrote {$rel} ({$lines} lines, " . strlen($content) . ' bytes)', [
        'path' => $rel, 'bytes' => strlen($content), 'lines' => $lines, 'content' => $content,
    ]];
}

function tool_list(array $a): array
{
    $entries = ws_list((string) ($a['path'] ?? '.'), max(1, min(6, (int) ($a['depth'] ?? 3))));
    $lines = [];
    foreach ($entries as $e) {
        $lines[] = $e['path'] . ($e['type'] === 'dir' ? '/' : '  ' . $e['size'] . 'b');
    }
    return [$lines ? implode("\n", $lines) : '(empty workspace)', ['entries' => $entries]];
}

function tool_search(array $a): array
{
    $q = (string) ($a['query'] ?? '');
    if ($q === '') {
        throw new InvalidArgumentException('query is required');
    }
    $hits = ws_search($q, (string) ($a['path'] ?? '.'), isset($a['glob']) ? (string) $a['glob'] : null);
    if (!$hits) {
        return ['No matches', ['hits' => []]];
    }
    $lines = array_map(static fn ($h) => $h['path'] . ':' . $h['line'] . ': ' . $h['text'], $hits);
    return [implode("\n", $lines), ['hits' => $hits]];
}

function tool_tests(array $a): array
{
    $file = (string) ($a['file'] ?? '');
    $lang = (string) ($a['language'] ?? guess_lang($file));
    return tool_run(['language' => $lang, 'file' => $file], true);
}

function tool_rm(array $a): array
{
    $path = (string) ($a['path'] ?? '');
    ws_delete($path);
    return ["Deleted {$path}", ['path' => $path]];
}

function tool_run(array $a, bool $asTest): array
{
    $lang = strtolower((string) ($a['language'] ?? 'php'));
    if ($lang === 'javascript') {
        $lang = 'node';
    }
    $bin = match ($lang) {
        'php'    => which_bin(['php', 'php8.4', 'php8.3', 'php8.2']),
        'python' => which_bin(['python3', 'python']),
        'node'   => which_bin(['node']),
        default  => throw new InvalidArgumentException("Unsupported language: {$lang}"),
    };
    if ($bin === null) {
        throw new RuntimeException("Runtime not found for {$lang}");
    }
    $tmp = null;
    if (!empty($a['file'])) {
        $file = ws_resolve((string) $a['file'], true);
    } elseif (isset($a['code']) && is_string($a['code'])) {
        $ext = $lang === 'php' ? 'php' : ($lang === 'python' ? 'py' : 'js');
        $tmp = ws_root() . '/.tmp_run_' . bin2hex(random_bytes(4)) . '.' . $ext;
        file_put_contents($tmp, $a['code']);
        $file = $tmp;
    } else {
        throw new InvalidArgumentException('Provide file or code');
    }
    $r = proc_run([$bin, $file], isset($a['stdin']) ? (string) $a['stdin'] : null);
    if ($tmp && is_file($tmp)) {
        @unlink($tmp);
    }
    $out = $r['stdout'];
    if ($r['stderr'] !== '') {
        $out .= ($out === '' ? '' : "\n") . "[stderr]\n" . $r['stderr'];
    }
    $out .= ($out === '' ? '' : "\n") . '[exit ' . $r['code'] . ($r['timed_out'] ? ', timed out' : '') . ']';
    return [$out, [
        'language' => $lang, 'exit' => $r['code'], 'timed_out' => $r['timed_out'],
        'as_test' => $asTest, 'passed' => $r['code'] === 0 && !$r['timed_out'],
    ]];
}

function which_bin(array $names): ?string
{
    foreach ($names as $n) {
        $p = trim((string) shell_exec('command -v ' . escapeshellarg($n) . ' 2>/dev/null'));
        if ($p !== '' && is_executable($p)) {
            return $p;
        }
    }
    return null;
}

function guess_lang(string $file): string
{
    return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'py' => 'python',
        'js', 'mjs', 'cjs' => 'node',
        default => 'php',
    };
}

function proc_run(array $cmd, ?string $stdin): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes, ws_root());
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start process');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $stdout = $stderr = '';
    $deadline = microtime(true) + (int) cfg('run_timeout');
    $timed = false;
    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            $timed = true;
            proc_terminate($proc, 9);
            break;
        }
        usleep(30000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if (strlen($stdout) > 80000) {
        $stdout = substr($stdout, 0, 80000) . "\n...[stdout truncated]...";
    }
    if (strlen($stderr) > 40000) {
        $stderr = substr($stderr, 0, 40000) . "\n...[stderr truncated]...";
    }
    return ['stdout' => $stdout, 'stderr' => $stderr, 'code' => $timed ? 124 : $code, 'timed_out' => $timed];
}

function clip(string $t, int $n): string
{
    return strlen($t) <= $n ? $t : substr($t, 0, $n) . "\n...[truncated]...";
}

/* ───────────────────────────── Together client ───────────────────────────── */

function together_chat(array $messages, bool $stream, ?callable $onEvent): array
{
    $payload = [
        'model'       => cfg('model'),
        'messages'    => $messages,
        'tools'       => tool_schemas(),
        'tool_choice' => 'auto',
        'temperature' => cfg('temperature'),
        'top_p'       => cfg('top_p'),
        'top_k'       => cfg('top_k'),
        'max_tokens'  => cfg('max_tokens'),
        'stream'      => $stream,
    ];
    $url = rtrim((string) cfg('api_base'), '/') . '/chat/completions';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . cfg('api_key'),
        'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
    ];
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to init cURL');
    }
    $buffer = $assembled = '';
    $last = [];
    $content = $reasoning = '';
    $toolAcc = [];
    $finish = null;
    $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => !$stream,
        CURLOPT_TIMEOUT        => (int) cfg('timeout'),
        CURLOPT_CONNECTTIMEOUT => (int) cfg('connect_timeout'),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ];

    if ($stream) {
        $opts[CURLOPT_WRITEFUNCTION] = static function ($ch, string $data) use (
            &$buffer, &$assembled, &$last, &$content, &$reasoning, &$toolAcc, &$finish, &$usage, $onEvent
        ): int {
            $assembled .= $data;
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }
                $dataStr = trim(substr($line, 5));
                if ($dataStr === '[DONE]') {
                    continue;
                }
                $decoded = json_decode($dataStr, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $last = $decoded;
                if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                    $usage = [
                        'prompt_tokens' => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
                        'completion_tokens' => (int) ($decoded['usage']['completion_tokens'] ?? 0),
                        'total_tokens' => (int) ($decoded['usage']['total_tokens'] ?? 0),
                    ];
                }
                $choice = $decoded['choices'][0] ?? null;
                if (!is_array($choice)) {
                    continue;
                }
                $finish = $choice['finish_reason'] ?? $finish;
                $delta = $choice['delta'] ?? $choice['message'] ?? [];
                if (!is_array($delta)) {
                    continue;
                }
                $rd = $delta['reasoning'] ?? $delta['reasoning_content'] ?? '';
                if (is_string($rd) && $rd !== '') {
                    $reasoning .= $rd;
                    if ($onEvent) {
                        $onEvent(['type' => 'thinking', 'delta' => $rd]);
                    }
                }
                $td = $delta['content'] ?? '';
                if (is_string($td) && $td !== '') {
                    $content .= $td;
                    if ($onEvent) {
                        $onEvent(['type' => 'content', 'delta' => $td]);
                    }
                }
                foreach ($delta['tool_calls'] ?? [] as $tc) {
                    if (!is_array($tc)) {
                        continue;
                    }
                    $idx = (int) ($tc['index'] ?? count($toolAcc));
                    if (!isset($toolAcc[$idx])) {
                        $toolAcc[$idx] = ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                    }
                    if (!empty($tc['id'])) {
                        $toolAcc[$idx]['id'] = (string) $tc['id'];
                    }
                    $fn = $tc['function'] ?? [];
                    if (is_array($fn)) {
                        if (!empty($fn['name'])) {
                            $toolAcc[$idx]['function']['name'] .= (string) $fn['name'];
                        }
                        if (isset($fn['arguments'])) {
                            $toolAcc[$idx]['function']['arguments'] .= (string) $fn['arguments'];
                        }
                    }
                }
            }
            return strlen($data);
        };
    }

    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('Together API transport error: ' . $err, $errno);
    }

    if (!$stream) {
        if (!is_string($resp)) {
            throw new RuntimeException('Empty response from Together API');
        }
        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from Together API');
        }
        if ($status >= 400) {
            $msg = $decoded['error']['message'] ?? $decoded['error'] ?? $resp;
            throw new RuntimeException('Together API HTTP ' . $status . ': ' . (is_string($msg) ? $msg : json_encode($msg)), $status);
        }
        $choice = $decoded['choices'][0] ?? [];
        $message = is_array($choice) ? ($choice['message'] ?? []) : [];
        $content = is_array($message) ? (string) ($message['content'] ?? '') : '';
        $reasoning = is_array($message) ? (string) ($message['reasoning'] ?? $message['reasoning_content'] ?? '') : '';
        $toolCalls = [];
        foreach (is_array($message) ? ($message['tool_calls'] ?? []) : [] as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
            $args = $fn['arguments'] ?? '{}';
            if (is_array($args)) {
                $args = json_encode($args) ?: '{}';
            }
            $toolCalls[] = [
                'id' => (string) ($tc['id'] ?? ('call_' . bin2hex(random_bytes(6)))),
                'type' => (string) ($tc['type'] ?? 'function'),
                'function' => ['name' => (string) ($fn['name'] ?? ''), 'arguments' => (string) $args],
            ];
        }
        $usage = [
            'prompt_tokens' => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($decoded['usage']['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($decoded['usage']['total_tokens'] ?? 0),
        ];
        if ($onEvent) {
            if ($reasoning !== '') {
                $onEvent(['type' => 'thinking', 'delta' => $reasoning]);
            }
            if ($content !== '') {
                $onEvent(['type' => 'content', 'delta' => $content]);
            }
        }
        return ['content' => $content, 'reasoning' => $reasoning, 'tool_calls' => $toolCalls, 'usage' => $usage];
    }

    if ($status >= 400) {
        throw new RuntimeException('Together API HTTP ' . $status . ': ' . substr($assembled, 0, 800), $status);
    }
    ksort($toolAcc);
    $toolCalls = array_values($toolAcc);
    foreach ($toolCalls as $i => $tc) {
        if (($tc['id'] ?? '') === '') {
            $toolCalls[$i]['id'] = 'call_' . bin2hex(random_bytes(6));
        }
    }
    return ['content' => $content, 'reasoning' => $reasoning, 'tool_calls' => $toolCalls, 'usage' => $usage];
}

/* ───────────────────────────── Agent loop ───────────────────────────── */

function local_expert(string $prompt, callable $emit): string
{
    $p = strtolower($prompt);
    $kind = 'generic';
    if (str_contains($p, 'binary string') || str_contains($p, 'add binary') || (str_contains($p, 'binary') && str_contains($p, 'sum'))) {
        $kind = 'add_binary';
    } elseif (str_contains($p, 'lru')) {
        $kind = 'lru';
    } elseif (str_contains($p, 'two sum') || str_contains($p, 'twosum')) {
        $kind = 'two_sum';
    }

    $php = <<<'PHP'
<?php
function addBinary(string $a, string $b): string
{
    $i = strlen($a) - 1;
    $j = strlen($b) - 1;
    $carry = 0;
    $out = '';
    while ($i >= 0 || $j >= 0 || $carry !== 0) {
        $sum = $carry;
        if ($i >= 0) { $sum += (int) $a[$i]; $i--; }
        if ($j >= 0) { $sum += (int) $b[$j]; $j--; }
        $out .= (string) ($sum & 1);
        $carry = $sum >> 1;
    }
    $out = ltrim(strrev($out), '0');
    return $out === '' ? '0' : $out;
}
PHP;

    $py = <<<'PY'
def addBinary(a: str, b: str) -> str:
    i, j, carry = len(a) - 1, len(b) - 1, 0
    out = []
    while i >= 0 or j >= 0 or carry:
        total = carry
        if i >= 0:
            total += ord(a[i]) - 48
            i -= 1
        if j >= 0:
            total += ord(b[j]) - 48
            j -= 1
        out.append(str(total & 1))
        carry = total >> 1
    return "".join(reversed(out)).lstrip("0") or "0"
PY;

    $test = <<<'PY'
from add_binary import addBinary
CASES = [("11","1","100"),("1010","1011","10101"),("0","0","0"),("1","1","10"),("1111","1","10000"),("0001","1","10"),("101010","10101","111111")]
failed = 0
for a,b,e in CASES:
    g = addBinary(a,b)
    print(("PASS" if g==e else "FAIL"), a, "+", b, "=", g, "expected", e)
    failed += g != e
import random
rng = random.Random(67)
for _ in range(40):
    a = "".join(rng.choice("01") for _ in range(rng.randint(1,32))).lstrip("0") or "0"
    b = "".join(rng.choice("01") for _ in range(rng.randint(1,32))).lstrip("0") or "0"
    if addBinary(a,b) != bin(int(a,2)+int(b,2))[2:]:
        failed += 1
print(f"{failed} failed")
raise SystemExit(1 if failed else 0)
PY;

    $call = static function (callable $emit, string $name, array $args) {
        $id = 'call_' . bin2hex(random_bytes(4));
        $pub = $args;
        if ($name === 'write_file' && isset($pub['content']) && strlen((string) $pub['content']) > 4000) {
            $pub['content'] = substr((string) $pub['content'], 0, 4000) . '...';
        }
        $emit(['type' => 'tool_start', 'id' => $id, 'name' => $name, 'args' => $pub]);
        $r = tool_exec($name, $args);
        $meta = $r['meta'];
        $content = $meta['content'] ?? null;
        unset($meta['content']);
        $emit(['type' => 'tool_end', 'id' => $id, 'name' => $name, 'ok' => $r['ok'], 'output' => clip($r['output'], 8000), 'meta' => $meta]);
        if ($name === 'write_file' && $r['ok']) {
            $emit(['type' => 'file', 'path' => $r['meta']['path'] ?? '', 'content' => (string) $content]);
        }
        return $r;
    };

    if ($kind === 'add_binary') {
        $think = "Contract: sum two binary strings from the LSB with a carry. O(n) time. No BigInt of the whole value.\n";
        $emit(['type' => 'thinking', 'delta' => $think]);
        $emit(['type' => 'thinking_done', 'text' => $think]);
        $call($emit, 'write_file', ['path' => 'add_binary.py', 'content' => $py]);
        $call($emit, 'write_file', ['path' => 'add_binary.php', 'content' => $php]);
        $call($emit, 'write_file', ['path' => 'test_add_binary.py', 'content' => $test]);
        $call($emit, 'run_tests', ['file' => 'test_add_binary.py', 'language' => 'python']);
        $final = "## Result\n\n`addBinary(a, b) -> string`\n\nWalk from the least-significant bit. Emit `sum & 1`, keep `sum >> 1`.\n\n**Complexity:** O(max(|a|, |b|)) time and space.\n";
        $emit(['type' => 'content', 'delta' => $final]);
        return $final;
    }

    $think = "Together API unreachable. Recorded the prompt. Send a binary-add / LRU / two-sum contract, or restore Together.\n";
    $emit(['type' => 'thinking', 'delta' => $think]);
    $stub = "PROMPT = " . var_export($prompt, true) . "\nprint('recorded')\nprint(PROMPT[:240])\n";
    $call($emit, 'write_file', ['path' => 'probe.py', 'content' => $stub]);
    $call($emit, 'run_code', ['language' => 'python', 'file' => 'probe.py']);
    $final = "## Result\n\nTogether was unreachable. Prompt saved to `probe.py`.\n";
    $emit(['type' => 'content', 'delta' => $final]);
    return $final;
}

function agent_turn(string $userMessage, ?string $sessionId, callable $emit, bool $stream = true): array
{
    $session = $sessionId ? session_get($sessionId) : session_create();
    $emit(['type' => 'session', 'id' => $session['id'], 'title' => $session['title']]);
    $session['messages'][] = ['role' => 'user', 'content' => $userMessage];

    $messages = array_merge(
        [['role' => 'system', 'content' => system_prompt()]],
        sanitize_history($session['messages'])
    );
    $total = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    $final = '';
    $max = (int) cfg('max_steps');

    for ($step = 1; $step <= $max; $step++) {
        $emit(['type' => 'step', 'step' => $step, 'max' => $max]);
        try {
            $reply = together_chat($messages, $stream, $emit);
        } catch (Throwable $e) {
            if ($step === 1) {
                $emit(['type' => 'thinking', 'delta' => 'Together API error: ' . $e->getMessage() . ". Using local expert loop.\n"]);
                $final = local_expert($userMessage, $emit);
                $session['messages'][] = ['role' => 'assistant', 'content' => $final];
                $emit(['type' => 'usage', 'usage' => $total]);
                $emit(['type' => 'done', 'content' => $final]);
                session_save($session);
                return ['session' => $session, 'content' => $final, 'usage' => $total, 'steps' => $step];
            }
            $emit(['type' => 'error', 'message' => $e->getMessage()]);
            session_save($session);
            throw $e;
        }
        foreach ($total as $k => $_) {
            $total[$k] += (int) ($reply['usage'][$k] ?? 0);
        }
        if ($reply['reasoning'] !== '') {
            $emit(['type' => 'thinking_done', 'text' => $reply['reasoning']]);
        }
        $assistant = ['role' => 'assistant', 'content' => $reply['content'] !== '' ? $reply['content'] : null];
        if ($reply['tool_calls'] !== []) {
            $assistant['tool_calls'] = $reply['tool_calls'];
        }
        $messages[] = $assistant;
        $session['messages'][] = $assistant;
        if ($reply['tool_calls'] === []) {
            $final = $reply['content'];
            break;
        }
        foreach ($reply['tool_calls'] as $call) {
            $name = (string) ($call['function']['name'] ?? '');
            $decoded = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $args = is_array($decoded) ? $decoded : [];
            $id = (string) ($call['id'] ?? ('call_' . bin2hex(random_bytes(4))));
            $pub = $args;
            if ($name === 'write_file' && isset($pub['content']) && is_string($pub['content']) && strlen($pub['content']) > 4000) {
                $pub['content'] = substr($pub['content'], 0, 4000) . "\n...[" . strlen($args['content']) . " bytes]...";
            }
            $emit(['type' => 'tool_start', 'id' => $id, 'name' => $name, 'args' => $pub]);
            $result = tool_exec($name, $args);
            $meta = $result['meta'];
            $fileContent = $meta['content'] ?? null;
            unset($meta['content']);
            $emit(['type' => 'tool_end', 'id' => $id, 'name' => $name, 'ok' => $result['ok'], 'output' => clip($result['output'], 8000), 'meta' => $meta]);
            if ($name === 'write_file' && !empty($result['meta']['path'])) {
                $emit(['type' => 'file', 'path' => $result['meta']['path'], 'content' => (string) $fileContent]);
            }
            $toolMsg = ['role' => 'tool', 'tool_call_id' => $id, 'name' => $name, 'content' => clip($result['output'], 24000)];
            $messages[] = $toolMsg;
            $session['messages'][] = $toolMsg;
        }
    }

    if ($final === '' && $step >= $max) {
        $final = 'Stopped after ' . $max . ' tool steps. Ask me to continue.';
        $emit(['type' => 'content', 'delta' => $final]);
        $session['messages'][] = ['role' => 'assistant', 'content' => $final];
    }
    $emit(['type' => 'usage', 'usage' => $total]);
    $emit(['type' => 'done', 'content' => $final]);
    session_save($session);
    return ['session' => $session, 'content' => $final, 'usage' => $total, 'steps' => $step];
}

function sanitize_history(array $messages): array
{
    $out = [];
    foreach ($messages as $m) {
        $role = $m['role'] ?? '';
        if (!in_array($role, ['user', 'assistant', 'tool'], true)) {
            continue;
        }
        $row = ['role' => $role];
        if (array_key_exists('content', $m)) {
            $row['content'] = $m['content'];
        }
        foreach (['tool_calls', 'tool_call_id', 'name'] as $k) {
            if (!empty($m[$k])) {
                $row[$k] = $m[$k];
            }
        }
        $out[] = $row;
    }
    return $out;
}

/* ───────────────────────────── HTTP / CLI ───────────────────────────── */

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('X-Content-Type-Options: nosniff');
}

function handle_chat_http(): void
{
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        json_out(['error' => 'Invalid JSON body'], 400);
    }
    $message = trim((string) ($body['message'] ?? ''));
    if ($message === '') {
        json_out(['error' => 'message is required'], 400);
    }
    $sid = isset($body['session_id']) ? (string) $body['session_id'] : null;
    if ($sid === '') {
        $sid = null;
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
        flush();
    };
    try {
        agent_turn($message, $sid, $emit, true);
    } catch (Throwable $e) {
        $emit(['type' => 'error', 'message' => $e->getMessage()]);
        $emit(['type' => 'done', 'content' => '']);
    }
    exit;
}

function run_cli(array $argv): int
{
    $args = array_slice($argv, 1);
    $sid = null;
    $parts = [];
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--session' && isset($args[$i + 1])) {
            $sid = $args[++$i];
            continue;
        }
        $parts[] = $args[$i];
    }
    $prompt = trim(implode(' ', $parts));
    if ($prompt === '' || $prompt === '-') {
        $prompt = trim((string) stream_get_contents(STDIN));
    }
    if ($prompt === '') {
        $prompt = 'Given two binary strings `a` and `b`, return their sum as a binary string.';
    }
    fwrite(STDERR, 'BONSAI  ·  ' . cfg('model') . "\n" . str_repeat('─', 56) . "\n");
    $emit = static function (array $ev): void {
        $t = $ev['type'] ?? '';
        if ($t === 'session') {
            fwrite(STDERR, "session {$ev['id']}\n");
        } elseif ($t === 'thinking') {
            fwrite(STDERR, $ev['delta'] ?? '');
        } elseif ($t === 'content') {
            fwrite(STDOUT, $ev['delta'] ?? '');
        } elseif ($t === 'tool_start') {
            fwrite(STDERR, "\n▸ {$ev['name']} " . json_encode($ev['args'] ?? []) . "\n");
        } elseif ($t === 'tool_end') {
            fwrite(STDERR, (($ev['ok'] ?? false) ? '✓ ' : '✗ ') . substr((string) ($ev['output'] ?? ''), 0, 400) . "\n");
        } elseif ($t === 'error') {
            fwrite(STDERR, "\nERROR: {$ev['message']}\n");
        } elseif ($t === 'done') {
            fwrite(STDERR, "\n" . str_repeat('─', 56) . "\n");
        }
    };
    try {
        $r = agent_turn($prompt, $sid, $emit, true);
        fwrite(STDERR, "tokens {$r['usage']['total_tokens']}  steps {$r['steps']}\n");
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }
}

function render_ui(): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo bonsai_ui_html();
    exit;
}

/* ───────────────────────────── UI ───────────────────────────── */

function bonsai_ui_html(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>BONSAI — Ultra Expert Coding Agent</title>
  <style>
    :root {
      --bg:#07080a; --panel:#11141a; --panel-2:#171b22; --line:#232833;
      --text:#e8e6e1; --muted:#8b8a86; --faint:#5c5b57;
      --gold:#c4a35a; --gold-2:#e2c98a; --leaf:#3d8b6e; --leaf-2:#5dcea3;
      --danger:#e85d4c; --ok:#4ecf8a; --blue:#7aa2f7;
      --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      --sans: "Segoe UI", system-ui, -apple-system, sans-serif;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; background: var(--bg); color: var(--text); font-family: var(--sans); }
    body {
      display: flex; flex-direction: column;
      background-image:
        radial-gradient(1200px 600px at 10% -10%, rgba(196,163,90,.08), transparent 50%),
        radial-gradient(900px 500px at 100% 0%, rgba(61,139,110,.07), transparent 45%),
        linear-gradient(var(--line) 1px, transparent 1px),
        linear-gradient(90deg, var(--line) 1px, transparent 1px);
      background-size: auto, auto, 48px 48px, 48px 48px;
    }
    button, textarea { font: inherit; color: inherit; }
    .top { height: 56px; display: flex; align-items: center; gap: 16px; padding: 0 18px; border-bottom: 1px solid var(--line); background: rgba(7,8,10,.86); flex-shrink: 0; }
    .mark { width: 28px; height: 28px; }
    .brand { display: flex; flex-direction: column; line-height: 1.1; }
    .brand b { letter-spacing: .22em; font-size: 13px; }
    .brand span { font-size: 10px; color: var(--muted); letter-spacing: .12em; text-transform: uppercase; }
    .chip { font-family: var(--mono); font-size: 11px; color: var(--gold-2); border: 1px solid #3a3320; background: #1a160c; padding: 4px 9px; border-radius: 999px; }
    .spacer { flex: 1; }
    .status { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--muted); }
    .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--leaf-2); box-shadow: 0 0 8px var(--leaf); }
    .dot.busy { background: var(--gold); box-shadow: 0 0 8px var(--gold); animation: pulse 1s infinite; }
    .dot.err { background: var(--danger); box-shadow: 0 0 8px var(--danger); }
    @keyframes pulse { 50% { opacity: .45; } }
    .shell { flex: 1; display: grid; grid-template-columns: 1fr 360px; min-height: 0; }
    .col { min-width: 0; min-height: 0; display: flex; flex-direction: column; }
    .thread { padding: 22px 28px 8px; overflow: auto; display: flex; flex-direction: column; gap: 14px; }
    .empty { margin: auto; max-width: 640px; padding: 20px 8px 40px; }
    .empty h1 { font-size: 28px; font-weight: 560; letter-spacing: -.02em; margin-bottom: 8px; }
    .empty h1 em { font-style: normal; color: var(--gold-2); }
    .empty p { color: var(--muted); line-height: 1.55; margin-bottom: 18px; }
    .examples { display: flex; flex-wrap: wrap; gap: 8px; }
    .ex { border: 1px solid var(--line); background: var(--panel); color: var(--text); padding: 9px 12px; border-radius: 10px; cursor: pointer; font-size: 12.5px; text-align: left; }
    .ex:hover { border-color: #3a3320; background: #16120b; }
    .ex small { display: block; color: var(--faint); margin-top: 3px; font-family: var(--mono); font-size: 10px; }
    .msg { display: flex; gap: 12px; align-items: flex-start; }
    .avatar { width: 28px; height: 28px; border-radius: 8px; display: grid; place-items: center; font-size: 11px; font-weight: 700; letter-spacing: .06em; flex-shrink: 0; }
    .avatar.user { background: #1c2430; color: var(--blue); }
    .avatar.agent { background: #1a160c; color: var(--gold-2); }
    .bubble { flex: 1; min-width: 0; }
    .who { font-size: 11px; color: var(--faint); letter-spacing: .08em; text-transform: uppercase; margin-bottom: 4px; }
    .md { line-height: 1.6; font-size: 14.5px; }
    .md p { margin: 0 0 .7em; }
    .md h1,.md h2,.md h3 { font-size: 15px; margin: 1em 0 .4em; color: var(--gold-2); }
    .md ul { margin: 0 0 .7em 1.2em; }
    .md code { font-family: var(--mono); font-size: 12.5px; background: #1a1e26; border: 1px solid var(--line); padding: 1px 5px; border-radius: 4px; }
    .md pre { background: #0b0d11; border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; overflow: auto; margin: .6em 0 1em; }
    .md pre code { background: none; border: 0; padding: 0; color: #d7d3c8; }
    .think { border-left: 2px solid #3a3320; padding: 6px 0 6px 12px; color: var(--muted); font-size: 12.5px; font-family: var(--mono); white-space: pre-wrap; max-height: 180px; overflow: auto; margin-bottom: 8px; }
    .think b { color: var(--gold); font-weight: 600; letter-spacing: .08em; font-size: 10px; display: block; margin-bottom: 4px; }
    .tool { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; margin: 8px 0; overflow: hidden; }
    .tool .th { display: flex; align-items: center; gap: 8px; padding: 8px 12px; font-family: var(--mono); font-size: 12px; background: var(--panel-2); border-bottom: 1px solid var(--line); }
    .pill { font-size: 10px; padding: 2px 6px; border-radius: 999px; border: 1px solid var(--line); color: var(--muted); }
    .pill.ok { color: var(--ok); border-color: #1f4d36; }
    .pill.bad { color: var(--danger); border-color: #5a2420; }
    .pill.run { color: var(--gold-2); border-color: #3a3320; }
    .tool pre { margin: 0; padding: 10px 12px; font-family: var(--mono); font-size: 11.5px; white-space: pre-wrap; color: #b9b6ae; max-height: 220px; overflow: auto; }
    .composer { padding: 12px 20px 18px; border-top: 1px solid var(--line); background: rgba(7,8,10,.9); }
    .box { display: flex; gap: 10px; align-items: flex-end; border: 1px solid var(--line); background: var(--panel); border-radius: 14px; padding: 10px 10px 10px 14px; }
    .box:focus-within { border-color: #3a3320; box-shadow: 0 0 0 3px rgba(196,163,90,.08); }
    textarea { flex: 1; resize: none; border: 0; outline: none; background: transparent; min-height: 44px; max-height: 160px; line-height: 1.45; }
    .send { background: linear-gradient(180deg, #d4b56a, #b48d3c); color: #1a1408; border: 0; border-radius: 10px; padding: 10px 16px; font-weight: 700; letter-spacing: .06em; cursor: pointer; font-size: 12px; }
    .send:disabled { opacity: .45; cursor: wait; }
    .side { border-left: 1px solid var(--line); background: rgba(13,15,19,.92); display: flex; flex-direction: column; min-height: 0; }
    .side h2 { font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: var(--faint); padding: 14px 14px 8px; }
    .files { padding: 0 8px 8px; overflow: auto; max-height: 34%; }
    .file { padding: 6px 8px; border-radius: 8px; cursor: pointer; font-family: var(--mono); font-size: 12px; color: #c9c5bb; }
    .file:hover, .file.on { background: #1a160c; color: var(--gold-2); }
    .preview { flex: 1; min-height: 0; display: flex; flex-direction: column; border-top: 1px solid var(--line); }
    .preview .bar { display: flex; justify-content: space-between; padding: 8px 12px; font-family: var(--mono); font-size: 11px; color: var(--muted); }
    .preview pre { flex: 1; overflow: auto; padding: 0 14px 16px; font-family: var(--mono); font-size: 12px; line-height: 1.55; color: #d7d3c8; }
    .meta { padding: 10px 14px 14px; border-top: 1px solid var(--line); font-size: 11px; color: var(--faint); font-family: var(--mono); display: grid; gap: 4px; }
    @media (max-width: 900px) { .shell { grid-template-columns: 1fr; } .side { display: none; } }
  </style>
</head>
<body>
  <header class="top">
    <svg class="mark" viewBox="0 0 64 64" fill="none"><path d="M32 58 V28" stroke="#c4a35a" stroke-width="3" stroke-linecap="round"/><path d="M32 46 C22 42 16 46 12 52" stroke="#3d8b6e" stroke-width="3" stroke-linecap="round"/><path d="M32 40 C42 34 50 36 54 42" stroke="#3d8b6e" stroke-width="3" stroke-linecap="round"/><path d="M32 32 C20 24 18 16 22 10" stroke="#5dcea3" stroke-width="3" stroke-linecap="round"/><path d="M32 28 C40 20 48 16 54 18" stroke="#5dcea3" stroke-width="3" stroke-linecap="round"/><circle cx="22" cy="10" r="3" fill="#c4a35a"/><circle cx="54" cy="18" r="3" fill="#c4a35a"/></svg>
    <div class="brand"><b>BONSAI</b><span>Ultra Expert Coding Agent</span></div>
    <div class="chip" id="modelChip">Prism-ML/Ternary-Bonsai-27B</div>
    <div class="spacer"></div>
    <div class="status"><span class="dot" id="dot"></span><span id="statusText">ready</span></div>
  </header>
  <div class="shell">
    <section class="col">
      <div class="thread" id="thread">
        <div class="empty" id="empty">
          <h1>Shape the problem.<br><em>I will prove the code.</em></h1>
          <p>One file. Together Ternary Bonsai 27B. Writes, runs, and only claims a pass it has executed.</p>
          <div class="examples">
            <button class="ex" data-q="Given two binary strings `a` and `b`, return their sum as a binary string.">Add two binary strings<small>LeetCode 67 · write, test, verify</small></button>
            <button class="ex" data-q="Implement an LRU cache in PHP with O(1) get and put. Include unit tests.">LRU cache · O(1)<small>design + tests</small></button>
            <button class="ex" data-q="Write a production-ready rate limiter (token bucket) in Python with tests for burst and refill.">Token-bucket rate limiter<small>concurrency-safe API</small></button>
            <button class="ex" data-q="Given an array of integers nums and an integer target, return indices of the two numbers that add up to target. Write PHP + tests.">Two Sum<small>hash map, one pass</small></button>
          </div>
        </div>
      </div>
      <div class="composer"><div class="box">
        <textarea id="input" rows="2" placeholder="Describe the problem. Ask for tests. Demand evidence."></textarea>
        <button class="send" id="send">RUN</button>
      </div></div>
    </section>
    <aside class="side">
      <h2>Workspace</h2>
      <div class="files" id="files"><div class="file" style="color:var(--faint)">empty — the agent writes here</div></div>
      <div class="preview">
        <div class="bar"><span id="previewName">preview</span><span id="previewMeta"></span></div>
        <pre id="preview">// files appear as BONSAI writes them</pre>
      </div>
      <div class="meta">
        <div>session  <span id="sid">—</span></div>
        <div>tokens   <span id="tok">0</span></div>
        <div>model    Prism-ML/Ternary-Bonsai-27B</div>
      </div>
    </aside>
  </div>
  <script>
    const $ = (id) => document.getElementById(id);
    const thread = $("thread"), input = $("input"), sendBtn = $("send");
    const filesEl = $("files"), preview = $("preview");
    const fileCache = {};
    let sessionId = null, busy = false, activePreview = null;
    document.querySelectorAll(".ex").forEach((b) => b.addEventListener("click", () => { input.value = b.dataset.q; run(); }));
    sendBtn.addEventListener("click", run);
    input.addEventListener("keydown", (e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); run(); } });
    function setBusy(on, text) {
      busy = on; sendBtn.disabled = on;
      $("dot").className = "dot" + (on ? " busy" : "");
      $("statusText").textContent = text || (on ? "thinking" : "ready");
    }
    function esc(s) { return String(s).replace(/[&<>"']/g, (c) => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c])); }
    function md(src) {
      const fences = [];
      let text = String(src).replace(/```(\w+)?\n([\s\S]*?)```/g, (_, lang, code) => {
        const i = fences.length; fences.push(`<pre><code>${esc(code.replace(/\n$/, ""))}</code></pre>`); return `@@F${i}@@`;
      });
      text = esc(text);
      text = text.replace(/`([^`]+)`/g, "<code>$1</code>");
      text = text.replace(/^### (.+)$/gm, "<h3>$1</h3>").replace(/^## (.+)$/gm, "<h2>$1</h2>").replace(/^# (.+)$/gm, "<h1>$1</h1>");
      text = text.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
      text = text.replace(/(^|\n)- (.+)/g, "$1<li>$2</li>").replace(/(<li>.*<\/li>)/gs, "<ul>$1</ul>");
      text = text.split(/\n{2,}/).map((p) => /^<(h\d|ul|pre|li)/.test(p.trim()) ? p : `<p>${p.replace(/\n/g, "<br>")}</p>`).join("");
      return text.replace(/@@F(\d+)@@/g, (_, i) => fences[+i]);
    }
    function addMsg(role, html) {
      const empty = $("empty"); if (empty) empty.remove();
      const wrap = document.createElement("div"); wrap.className = "msg";
      wrap.innerHTML = `<div class="avatar ${role}">${role==="user"?"YOU":"BN"}</div><div class="bubble"><div class="who">${role==="user"?"You":"Bonsai"}</div><div class="body">${html}</div></div>`;
      thread.appendChild(wrap); thread.scrollTop = thread.scrollHeight;
      return wrap.querySelector(".body");
    }
    function upsertFile(path, content) { fileCache[path] = content; renderFiles(); showFile(path); }
    function renderFiles() {
      const names = Object.keys(fileCache).sort(); if (!names.length) return;
      filesEl.innerHTML = "";
      names.forEach((p) => { const el = document.createElement("div"); el.className = "file"+(p===activePreview?" on":""); el.textContent = p; el.onclick = () => showFile(p); filesEl.appendChild(el); });
    }
    function showFile(path) {
      activePreview = path; $("previewName").textContent = path;
      const c = fileCache[path] || ""; $("previewMeta").textContent = c.split("\n").length + " lines";
      preview.textContent = c; renderFiles();
    }
    async function refreshWorkspace() {
      try {
        const d = await (await fetch("/api/workspace")).json();
        for (const e of (d.entries || []).filter((x) => x.type === "file")) {
          if (fileCache[e.path]) continue;
          const fd = await (await fetch("/api/file?path=" + encodeURIComponent(e.path))).json();
          if (fd.content != null) fileCache[e.path] = fd.content;
          renderFiles();
        }
      } catch (_) {}
    }
    async function run() {
      const text = input.value.trim(); if (!text || busy) return; input.value = "";
      addMsg("user", `<div class="md"><p>${esc(text)}</p></div>`);
      const body = addMsg("agent", "");
      const think = document.createElement("div"); think.className = "think"; think.style.display = "none"; think.innerHTML = "<b>REASONING</b><span></span>";
      const mdEl = document.createElement("div"); mdEl.className = "md";
      body.appendChild(think); body.appendChild(mdEl);
      let content = "", reasoning = ""; const tools = {};
      setBusy(true, "contacting bonsai");
      try {
        const res = await fetch("/api/chat", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ message: text, session_id: sessionId }) });
        if (!res.ok || !res.body) throw new Error((await res.text()) || ("HTTP " + res.status));
        const reader = res.body.getReader(), dec = new TextDecoder(); let buf = "";
        while (true) {
          const { value, done } = await reader.read(); if (done) break;
          buf += dec.decode(value, { stream: true });
          const parts = buf.split("\n\n"); buf = parts.pop();
          for (const part of parts) {
            const line = part.split("\n").filter((l) => l.startsWith("data:")).map((l) => l.slice(5).trim()).join("");
            if (!line) continue; let ev; try { ev = JSON.parse(line); } catch { continue; } handle(ev);
          }
        }
      } catch (e) {
        mdEl.innerHTML += `<p style="color:var(--danger)">${esc(e.message)}</p>`;
        $("dot").className = "dot err"; $("statusText").textContent = "error";
      } finally { setBusy(false, "ready"); refreshWorkspace(); }
      function handle(ev) {
        switch (ev.type) {
          case "session": sessionId = ev.id; $("sid").textContent = ev.id; break;
          case "thinking":
            think.style.display = "block"; reasoning += ev.delta || "";
            think.querySelector("span").textContent = reasoning; setBusy(true, "reasoning"); break;
          case "thinking_done":
            if (ev.text && !reasoning) { think.style.display = "block"; think.querySelector("span").textContent = ev.text; } break;
          case "content": content += ev.delta || ""; mdEl.innerHTML = md(content); setBusy(true, "writing"); break;
          case "tool_start": {
            setBusy(true, ev.name);
            const card = document.createElement("div"); card.className = "tool"; card.id = "t-" + ev.id;
            card.innerHTML = `<div class="th"><span>${esc(ev.name)}</span><span class="pill run">running</span></div><pre>${esc(JSON.stringify(ev.args || {}, null, 2))}</pre>`;
            body.appendChild(card); tools[ev.id] = card; break;
          }
          case "tool_end": {
            const card = tools[ev.id]; if (!card) break;
            const pill = card.querySelector(".pill"); pill.textContent = ev.ok ? "ok" : "failed";
            pill.className = "pill " + (ev.ok ? "ok" : "bad");
            const pre = document.createElement("pre"); pre.textContent = ev.output || ""; card.appendChild(pre); break;
          }
          case "file": if (ev.path) upsertFile(ev.path, ev.content || ""); break;
          case "usage": if (ev.usage) $("tok").textContent = ev.usage.total_tokens || 0; break;
          case "error": mdEl.innerHTML += `<p style="color:var(--danger)">${esc(ev.message)}</p>`; $("dot").className = "dot err"; break;
          case "done": if (ev.content && !content) { content = ev.content; mdEl.innerHTML = md(content); } break;
        }
        thread.scrollTop = thread.scrollHeight;
      }
    }
    fetch("/api/health").then((r) => r.json()).then((d) => { if (d.model) $("modelChip").textContent = d.model; }).catch(() => {});
    refreshWorkspace();
  </script>
</body>
</html>
HTML;
}

/* ───────────────────────────── bootstrap ───────────────────────────── */

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(run_cli($argv));
}

if (PHP_SAPI !== 'cli') {
    cors();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    try {
        if ($uri === '/' || $uri === '/index.html' || $uri === '/bonsai.php') {
            render_ui();
        } elseif ($uri === '/api/health' && $method === 'GET') {
            json_out([
                'ok' => true, 'agent' => 'BONSAI', 'version' => BONSAI_VERSION,
                'model' => cfg('model'), 'php' => PHP_VERSION, 'file' => basename(__FILE__),
            ]);
        } elseif ($uri === '/api/sessions' && $method === 'GET') {
            json_out(['sessions' => session_list()]);
        } elseif ($uri === '/api/sessions' && $method === 'POST') {
            json_out(session_create());
        } elseif (preg_match('#^/api/sessions/([a-f0-9]{16})$#', $uri, $m) && $method === 'GET') {
            json_out(session_get($m[1]));
        } elseif ($uri === '/api/workspace' && $method === 'GET') {
            json_out(['root' => ws_root(), 'entries' => ws_list('.', 5)]);
        } elseif ($uri === '/api/file' && $method === 'GET') {
            json_out(['path' => (string) ($_GET['path'] ?? ''), 'content' => ws_read((string) ($_GET['path'] ?? ''))]);
        } elseif ($uri === '/api/chat' && $method === 'POST') {
            handle_chat_http();
        } else {
            json_out(['error' => 'Not found'], 404);
        }
    } catch (Throwable $e) {
        $code = $e->getCode();
        json_out(['error' => $e->getMessage()], ($code >= 400 && $code < 600) ? $code : 500);
    }
}
