<?php

declare(strict_types=1);

namespace Bonsai;

use RuntimeException;

/**
 * OpenAI-compatible Together Chat Completions client.
 * Handles tool calls, reasoning traces, and SSE streaming.
 */
final class TogetherClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 180,
        private readonly int $connectTimeout = 20,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param callable(array<string, mixed>):void|null $onEvent
     * @return array{content:string,reasoning:string,tool_calls:list<array<string,mixed>>,finish_reason:?string,usage:array<string,int>,raw:array<string,mixed>}
     */
    public function chat(
        string $model,
        array $messages,
        array $tools = [],
        float $temperature = 0.3,
        float $topP = 0.95,
        int $topK = 20,
        int $maxTokens = 8192,
        bool $stream = false,
        ?callable $onEvent = null,
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'top_p' => $topP,
            'top_k' => $topK,
            'max_tokens' => $maxTokens,
            'stream' => $stream,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        if ($stream) {
            return $this->chatStream($payload, $onEvent);
        }

        $raw = $this->request('/chat/completions', $payload, false, null);
        return $this->normalize($raw);
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(array<string, mixed>):void|null $onEvent
     * @return array{content:string,reasoning:string,tool_calls:list<array<string,mixed>>,finish_reason:?string,usage:array<string,int>,raw:array<string,mixed>}
     */
    private function chatStream(array $payload, ?callable $onEvent): array
    {
        $content = '';
        $reasoning = '';
        $toolAcc = [];
        $finish = null;
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $rawLast = [];

        $this->request('/chat/completions', $payload, true, function (array $chunk) use (
            &$content,
            &$reasoning,
            &$toolAcc,
            &$finish,
            &$usage,
            &$rawLast,
            $onEvent,
        ): void {
            $rawLast = $chunk;
            if (isset($chunk['usage']) && is_array($chunk['usage'])) {
                $usage = [
                    'prompt_tokens' => (int) ($chunk['usage']['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($chunk['usage']['completion_tokens'] ?? 0),
                    'total_tokens' => (int) ($chunk['usage']['total_tokens'] ?? 0),
                ];
            }
            $choice = $chunk['choices'][0] ?? null;
            if (!is_array($choice)) {
                return;
            }
            $finish = $choice['finish_reason'] ?? $finish;
            $delta = $choice['delta'] ?? $choice['message'] ?? [];
            if (!is_array($delta)) {
                return;
            }

            $reasonDelta = $delta['reasoning'] ?? $delta['reasoning_content'] ?? '';
            if (is_string($reasonDelta) && $reasonDelta !== '') {
                $reasoning .= $reasonDelta;
                if ($onEvent) {
                    $onEvent(['type' => 'thinking', 'delta' => $reasonDelta]);
                }
            }

            $textDelta = $delta['content'] ?? '';
            if (is_string($textDelta) && $textDelta !== '') {
                $content .= $textDelta;
                if ($onEvent) {
                    $onEvent(['type' => 'content', 'delta' => $textDelta]);
                }
            }

            if (!empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    if (!is_array($tc)) {
                        continue;
                    }
                    $idx = (int) ($tc['index'] ?? count($toolAcc));
                    if (!isset($toolAcc[$idx])) {
                        $toolAcc[$idx] = [
                            'id' => '',
                            'type' => 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }
                    if (!empty($tc['id'])) {
                        $toolAcc[$idx]['id'] = (string) $tc['id'];
                    }
                    if (!empty($tc['type'])) {
                        $toolAcc[$idx]['type'] = (string) $tc['type'];
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
        });

        ksort($toolAcc);
        $toolCalls = array_values($toolAcc);
        foreach ($toolCalls as $i => $tc) {
            if (($tc['id'] ?? '') === '') {
                $toolCalls[$i]['id'] = 'call_' . bin2hex(random_bytes(6));
            }
        }

        return [
            'content' => $content,
            'reasoning' => $reasoning,
            'tool_calls' => $toolCalls,
            'finish_reason' => is_string($finish) ? $finish : null,
            'usage' => $usage,
            'raw' => $rawLast,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{content:string,reasoning:string,tool_calls:list<array<string,mixed>>,finish_reason:?string,usage:array<string,int>,raw:array<string,mixed>}
     */
    private function normalize(array $raw): array
    {
        $choice = $raw['choices'][0] ?? [];
        $message = is_array($choice) ? ($choice['message'] ?? []) : [];
        $content = is_array($message) ? (string) ($message['content'] ?? '') : '';
        $reasoning = '';
        if (is_array($message)) {
            $reasoning = (string) ($message['reasoning'] ?? $message['reasoning_content'] ?? '');
        }
        $toolCalls = [];
        if (is_array($message) && !empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                if (!is_array($tc)) {
                    continue;
                }
                $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                $args = $fn['arguments'] ?? '{}';
                if (is_array($args)) {
                    $args = json_encode($args, JSON_UNESCAPED_UNICODE) ?: '{}';
                }
                $toolCalls[] = [
                    'id' => (string) ($tc['id'] ?? ('call_' . bin2hex(random_bytes(6)))),
                    'type' => (string) ($tc['type'] ?? 'function'),
                    'function' => [
                        'name' => (string) ($fn['name'] ?? ''),
                        'arguments' => (string) $args,
                    ],
                ];
            }
        }
        $usage = [
            'prompt_tokens' => (int) ($raw['usage']['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($raw['usage']['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($raw['usage']['total_tokens'] ?? 0),
        ];

        return [
            'content' => $content,
            'reasoning' => $reasoning,
            'tool_calls' => $toolCalls,
            'finish_reason' => isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : null,
            'usage' => $usage,
            'raw' => $raw,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(array<string, mixed>):void|null $onChunk
     * @return array<string, mixed>
     */
    private function request(string $path, array $payload, bool $stream, ?callable $onChunk): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Failed to encode request payload');
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to init cURL');
        }

        $buffer = '';
        $assembled = '';
        $lastJson = [];

        $opts = [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => !$stream,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];

        if ($stream) {
            $opts[CURLOPT_WRITEFUNCTION] = function ($ch, string $data) use (&$buffer, &$assembled, &$lastJson, $onChunk): int {
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
                    if (is_array($decoded)) {
                        $lastJson = $decoded;
                        if ($onChunk) {
                            $onChunk($decoded);
                        }
                    }
                }
                return strlen($data);
            };
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Together API transport error: ' . $error, $errno);
        }

        if ($stream) {
            if ($status >= 400) {
                throw new RuntimeException('Together API HTTP ' . $status . ': ' . substr($assembled, 0, 800), $status);
            }
            return $lastJson;
        }

        if (!is_string($response)) {
            throw new RuntimeException('Empty response from Together API');
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from Together API: ' . substr($response, 0, 400));
        }
        if ($status >= 400) {
            $msg = $decoded['error']['message'] ?? $decoded['error'] ?? $response;
            if (is_array($msg)) {
                $msg = json_encode($msg) ?: 'unknown error';
            }
            throw new RuntimeException('Together API HTTP ' . $status . ': ' . $msg, $status);
        }

        return $decoded;
    }
}
