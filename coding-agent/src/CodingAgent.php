<?php

declare(strict_types=1);

namespace Bonsai;

use Throwable;

final class CodingAgent
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly TogetherClient $client,
        private readonly ToolRegistry $tools,
        private readonly SessionStore $sessions,
        private readonly Workspace $workspace,
    ) {
    }

    /**
     * @param callable(array<string, mixed>):void $emit
     * @return array{session:array<string,mixed>,content:string,usage:array<string,int>,steps:int}
     */
    public function turn(string $userMessage, ?string $sessionId, callable $emit, bool $stream = true): array
    {
        $session = $sessionId ? $this->sessions->get($sessionId) : $this->sessions->create();
        $emit(['type' => 'session', 'id' => $session['id'], 'title' => $session['title']]);

        $session['messages'][] = ['role' => 'user', 'content' => $userMessage];

        $messages = array_merge(
            [[
                'role' => 'system',
                'content' => SystemPrompt::build($this->workspace->root(), (string) $this->config['model']),
            ]],
            $this->sanitizeHistory($session['messages']),
        );

        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $finalContent = '';
        $maxSteps = (int) $this->config['max_steps'];

        for ($step = 1; $step <= $maxSteps; $step++) {
            $emit(['type' => 'step', 'step' => $step, 'max' => $maxSteps]);
            try {
                $reply = $this->client->chat(
                    model: (string) $this->config['model'],
                    messages: $messages,
                    tools: $this->tools->schemas(),
                    temperature: (float) $this->config['temperature'],
                    topP: (float) $this->config['top_p'],
                    topK: (int) $this->config['top_k'],
                    maxTokens: (int) $this->config['max_tokens'],
                    stream: $stream,
                    onEvent: static function (array $event) use ($emit): void {
                        $emit($event);
                    },
                );
            } catch (Throwable $e) {
                $emit(['type' => 'error', 'message' => $e->getMessage()]);
                $session['events'][] = ['type' => 'error', 'message' => $e->getMessage(), 'at' => time()];
                $this->sessions->save($session);
                throw $e;
            }

            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $k) {
                $totalUsage[$k] += (int) ($reply['usage'][$k] ?? 0);
            }

            if ($reply['reasoning'] !== '') {
                $emit(['type' => 'thinking_done', 'text' => $reply['reasoning']]);
            }

            $assistant = [
                'role' => 'assistant',
                'content' => $reply['content'] !== '' ? $reply['content'] : null,
            ];
            if ($reply['reasoning'] !== '') {
                $assistant['reasoning'] = $reply['reasoning'];
            }
            if ($reply['tool_calls'] !== []) {
                $assistant['tool_calls'] = $reply['tool_calls'];
            }
            $messages[] = $assistant;
            $session['messages'][] = $assistant;

            if ($reply['tool_calls'] === []) {
                $finalContent = $reply['content'];
                break;
            }

            foreach ($reply['tool_calls'] as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $rawArgs = (string) ($call['function']['arguments'] ?? '{}');
                $decoded = json_decode($rawArgs, true);
                $args = is_array($decoded) ? $decoded : [];
                $id = (string) ($call['id'] ?? ('call_' . bin2hex(random_bytes(4))));

                $emit([
                    'type' => 'tool_start',
                    'id' => $id,
                    'name' => $name,
                    'args' => $this->publicArgs($name, $args),
                ]);

                $result = $this->tools->execute($name, $args);
                $output = $result['output'];
                $emit([
                    'type' => 'tool_end',
                    'id' => $id,
                    'name' => $name,
                    'ok' => $result['ok'],
                    'output' => $this->clip($output, 8000),
                    'meta' => $this->publicMeta($result['meta']),
                ]);

                if ($name === 'write_file' && !empty($result['meta']['path'])) {
                    $emit([
                        'type' => 'file',
                        'path' => $result['meta']['path'],
                        'content' => (string) ($result['meta']['content'] ?? ''),
                    ]);
                }

                $toolMsg = [
                    'role' => 'tool',
                    'tool_call_id' => $id,
                    'name' => $name,
                    'content' => $this->clip($output, 24000),
                ];
                $messages[] = $toolMsg;
                $session['messages'][] = $toolMsg;
            }
        }

        if ($finalContent === '' && $step >= $maxSteps) {
            $finalContent = 'Stopped after ' . $maxSteps . ' tool steps. Ask me to continue.';
            $emit(['type' => 'content', 'delta' => $finalContent]);
            $session['messages'][] = ['role' => 'assistant', 'content' => $finalContent];
        }

        $emit(['type' => 'usage', 'usage' => $totalUsage]);
        $emit(['type' => 'done', 'content' => $finalContent]);
        $this->sessions->save($session);

        return [
            'session' => $session,
            'content' => $finalContent,
            'usage' => $totalUsage,
            'steps' => $step,
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function sanitizeHistory(array $messages): array
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
            if (!empty($m['tool_calls'])) {
                $row['tool_calls'] = $m['tool_calls'];
            }
            if (!empty($m['tool_call_id'])) {
                $row['tool_call_id'] = $m['tool_call_id'];
            }
            if (!empty($m['name'])) {
                $row['name'] = $m['name'];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function publicArgs(string $name, array $args): array
    {
        if ($name === 'write_file' && isset($args['content']) && is_string($args['content'])) {
            $copy = $args;
            $len = strlen($args['content']);
            $copy['content'] = $len > 4000
                ? substr($args['content'], 0, 4000) . "\n...[" . $len . " bytes]..."
                : $args['content'];
            return $copy;
        }
        return $args;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function publicMeta(array $meta): array
    {
        unset($meta['content']);
        return $meta;
    }

    private function clip(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }
        return substr($text, 0, $max) . "\n...[truncated]...";
    }
}
