<?php

declare(strict_types=1);

namespace Bonsai;

use Throwable;

final class ToolRegistry
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly int $runTimeout = 15,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function schemas(): array
    {
        return [
            $this->fn('read_file', 'Read a UTF-8 text file from the workspace.', [
                'path' => ['type' => 'string', 'description' => 'Path relative to the workspace'],
            ], ['path']),
            $this->fn('write_file', 'Create or overwrite a text file in the workspace. Creates parent directories.', [
                'path' => ['type' => 'string', 'description' => 'Path relative to the workspace'],
                'content' => ['type' => 'string', 'description' => 'Full file contents'],
            ], ['path', 'content']),
            $this->fn('list_dir', 'List files and directories in the workspace.', [
                'path' => ['type' => 'string', 'description' => 'Directory relative to workspace. Default: .'],
                'depth' => ['type' => 'integer', 'description' => 'Recursion depth (1-6). Default 3'],
            ], []),
            $this->fn('search_code', 'Search workspace text files for a substring (case-insensitive).', [
                'query' => ['type' => 'string', 'description' => 'Substring to find'],
                'path' => ['type' => 'string', 'description' => 'Root path to search. Default: .'],
                'glob' => ['type' => 'string', 'description' => 'Optional filename glob, e.g. *.php'],
            ], ['query']),
            $this->fn('run_code', 'Execute PHP, Python, or Node.js. Provide either file or inline code.', [
                'language' => ['type' => 'string', 'enum' => ['php', 'python', 'node', 'javascript'], 'description' => 'Runtime'],
                'file' => ['type' => 'string', 'description' => 'Workspace file to execute'],
                'code' => ['type' => 'string', 'description' => 'Inline source. Written to a temp file then executed'],
                'stdin' => ['type' => 'string', 'description' => 'Optional stdin'],
                'args' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional CLI arguments',
                ],
            ], ['language']),
            $this->fn('run_tests', 'Run a test file (php, python, node). Same as run_code but labeled as a test run.', [
                'file' => ['type' => 'string', 'description' => 'Workspace test file'],
                'language' => ['type' => 'string', 'enum' => ['php', 'python', 'node', 'javascript']],
            ], ['file']),
            $this->fn('delete_file', 'Delete a single file from the workspace.', [
                'path' => ['type' => 'string', 'description' => 'File path relative to workspace'],
            ], ['path']),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{ok:bool,name:string,output:string,meta:array<string,mixed>}
     */
    public function execute(string $name, array $arguments): array
    {
        try {
            $result = match ($name) {
                'read_file' => $this->readFile($arguments),
                'write_file' => $this->writeFile($arguments),
                'list_dir' => $this->listDir($arguments),
                'search_code' => $this->searchCode($arguments),
                'run_code' => $this->runCode($arguments, false),
                'run_tests' => $this->runTests($arguments),
                'delete_file' => $this->deleteFile($arguments),
                default => throw new \InvalidArgumentException("Unknown tool: {$name}"),
            };
            return ['ok' => true, 'name' => $name, 'output' => $result['output'], 'meta' => $result['meta']];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'name' => $name,
                'output' => 'ERROR: ' . $e->getMessage(),
                'meta' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{output:string,meta:array<string,mixed>}
     */
    private function readFile(array $args): array
    {
        $path = (string) ($args['path'] ?? '');
        $content = $this->workspace->read($path);
        $truncated = false;
        if (strlen($content) > 120000) {
            $content = substr($content, 0, 120000) . "\n...[truncated]...";
            $truncated = true;
        }
        return ['output' => $content, 'meta' => ['path' => $path, 'bytes' => strlen($content), 'truncated' => $truncated]];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{output:string,meta:array<string,mixed>}
     */
    private function writeFile(array $args): array
    {
        $path = (string) ($args['path'] ?? '');
        $content = (string) ($args['content'] ?? '');
        $rel = $this->workspace->write($path, $content);
        $lines = substr_count($content, "\n") + ($content === '' ? 0 : 1);
        return [
            'output' => "Wrote {$rel} ({$lines} lines, " . strlen($content) . ' bytes)',
            'meta' => ['path' => $rel, 'bytes' => strlen($content), 'lines' => $lines, 'content' => $content],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{output:string,meta:array<string,mixed>}
     */
    private function listDir(array $args): array
    {
        $path = (string) ($args['path'] ?? '.');
        $depth = (int) ($args['depth'] ?? 3);
        $depth = max(1, min(6, $depth));
        $entries = $this->workspace->list($path, $depth);
        $lines = [];
        foreach ($entries as $e) {
            $mark = $e['type'] === 'dir' ? '/' : '';
            $size = $e['type'] === 'dir' ? '' : '  ' . $e['size'] . 'b';
            $lines[] = $e['path'] . $mark . $size;
        }
        $text = $lines === [] ? '(empty workspace)' : implode("\n", $lines);
        return ['output' => $text, 'meta' => ['entries' => $entries]];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{output:string,meta:array<string,mixed>}
     */
    private function searchCode(array $args): array
    {
        $query = (string) ($args['query'] ?? '');
        if ($query === '') {
            throw new \InvalidArgumentException('query is required');
        }
        $hits = $this->workspace->search(
            $query,
            (string) ($args['path'] ?? '.'),
            isset($args['glob']) ? (string) $args['glob'] : null,
        );
        if ($hits === []) {
            return ['output' => 'No matches', 'meta' => ['hits' => []]];
        }
        $lines = array_map(
            static fn ($h) => $h['path'] . ':' . $h['line'] . ': ' . $h['text'],
            $hits,
        );
        return ['output' => implode("\n", $lines), 'meta' => ['hits' => $hits]];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{output:string,meta:array<string,mixed>}
     */
    private function runTests(array $args): array
    {
        $file = (string) ($args['file'] ?? '');
        $lang = (string) ($args['language'] ?? $this->guessLanguage($file));
        return $this->runCode(['language' => $lang, 'file' => $file], true);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{output:string,meta:array<string,mixed>}
     */
    private function runCode(array $args, bool $asTest): array
    {
        $lang = strtolower((string) ($args['language'] ?? 'php'));
        if ($lang === 'javascript') {
            $lang = 'node';
        }
        $bin = match ($lang) {
            'php' => $this->which(['php', 'php8.4', 'php8.3', 'php8.2']),
            'python' => $this->which(['python3', 'python']),
            'node' => $this->which(['node']),
            default => throw new \InvalidArgumentException("Unsupported language: {$lang}"),
        };
        if ($bin === null) {
            throw new \RuntimeException("Runtime not found for {$lang}");
        }

        $tmp = null;
        if (!empty($args['file'])) {
            $file = $this->workspace->resolve((string) $args['file'], true);
        } elseif (isset($args['code']) && is_string($args['code'])) {
            $ext = $lang === 'php' ? 'php' : ($lang === 'python' ? 'py' : 'js');
            $tmp = $this->workspace->root() . '/.tmp_run_' . bin2hex(random_bytes(4)) . '.' . $ext;
            file_put_contents($tmp, $args['code']);
            $file = $tmp;
        } else {
            throw new \InvalidArgumentException('Provide file or code');
        }

        $cmd = [$bin];
        if ($lang === 'php' && !str_ends_with($file, '.php') && isset($args['code'])) {
            $cmd = [$bin, '-r', (string) $args['code']];
            $file = null;
        } else {
            $cmd[] = $file;
        }
        if (!empty($args['args']) && is_array($args['args'])) {
            foreach ($args['args'] as $a) {
                $cmd[] = (string) $a;
            }
        }

        $result = $this->proc($cmd, isset($args['stdin']) ? (string) $args['stdin'] : null);
        if ($tmp !== null && is_file($tmp)) {
            @unlink($tmp);
        }

        $output = '';
        if ($result['stdout'] !== '') {
            $output .= $result['stdout'];
        }
        if ($result['stderr'] !== '') {
            $output .= ($output === '' ? '' : "\n") . '[stderr]\n' . $result['stderr'];
        }
        $output .= ($output === '' ? '' : "\n") . '[exit ' . $result['code'] . ($result['timed_out'] ? ', timed out' : '') . ']';

        return [
            'output' => $output,
            'meta' => [
                'language' => $lang,
                'exit' => $result['code'],
                'timed_out' => $result['timed_out'],
                'as_test' => $asTest,
                'passed' => $result['code'] === 0 && !$result['timed_out'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{output:string,meta:array<string,mixed>}
     */
    private function deleteFile(array $args): array
    {
        $path = (string) ($args['path'] ?? '');
        $this->workspace->delete($path);
        return ['output' => "Deleted {$path}", 'meta' => ['path' => $path]];
    }

    /**
     * @param list<string> $cmd
     * @return array{stdout:string,stderr:string,code:int,timed_out:bool}
     */
    private function proc(array $cmd, ?string $stdin): array
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $spec, $pipes, $this->workspace->root(), null);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start process');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->runTimeout;
        $timedOut = false;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
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
        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'code' => $timedOut ? 124 : $code,
            'timed_out' => $timedOut,
        ];
    }

    /**
     * @param list<string> $names
     */
    private function which(array $names): ?string
    {
        foreach ($names as $name) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    private function guessLanguage(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return match ($ext) {
            'py' => 'python',
            'js', 'mjs', 'cjs' => 'node',
            default => 'php',
        };
    }

    /**
     * @param array<string, array<string, mixed>> $props
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function fn(string $name, string $description, array $props, array $required): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $props,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $schema,
            ],
        ];
    }
}
