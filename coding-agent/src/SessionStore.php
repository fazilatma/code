<?php

declare(strict_types=1);

namespace Bonsai;

use RuntimeException;

final class SessionStore
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create sessions dir: {$dir}");
        }
    }

    /**
     * @return array{id:string,created:int,updated:int,title:string,messages:list<array<string,mixed>>,events:list<array<string,mixed>>}
     */
    public function create(?string $title = null): array
    {
        $id = bin2hex(random_bytes(8));
        $now = time();
        $session = [
            'id' => $id,
            'created' => $now,
            'updated' => $now,
            'title' => $title ?: 'Untitled session',
            'messages' => [],
            'events' => [],
        ];
        $this->write($session);
        return $session;
    }

    /**
     * @return array{id:string,created:int,updated:int,title:string,messages:list<array<string,mixed>>,events:list<array<string,mixed>>}
     */
    public function get(string $id): array
    {
        $file = $this->path($id);
        if (!is_file($file)) {
            throw new RuntimeException("Session not found: {$id}");
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || empty($data['id'])) {
            throw new RuntimeException("Corrupt session: {$id}");
        }
        return $data;
    }

    /**
     * @param array{id:string,created:int,updated:int,title:string,messages:list<array<string,mixed>>,events:list<array<string,mixed>>} $session
     */
    public function save(array $session): void
    {
        $session['updated'] = time();
        if (($session['title'] ?? '') === 'Untitled session') {
            foreach ($session['messages'] as $m) {
                if (($m['role'] ?? '') === 'user' && is_string($m['content'] ?? null) && $m['content'] !== '') {
                    $session['title'] = mb_substr($m['content'], 0, 72);
                    break;
                }
            }
        }
        $this->write($session);
    }

    /**
     * @return list<array{id:string,title:string,updated:int,created:int}>
     */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || empty($data['id'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $data['id'],
                'title' => (string) ($data['title'] ?? 'Untitled'),
                'updated' => (int) ($data['updated'] ?? 0),
                'created' => (int) ($data['created'] ?? 0),
            ];
        }
        usort($out, static fn ($a, $b) => $b['updated'] <=> $a['updated']);
        return $out;
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            throw new RuntimeException('Invalid session id');
        }
        return $this->dir . '/' . $id . '.json';
    }

    /**
     * @param array<string, mixed> $session
     */
    private function write(array $session): void
    {
        $file = $this->path((string) $session['id']);
        $json = json_encode($session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Failed to encode session');
        }
        file_put_contents($file, $json, LOCK_EX);
    }
}
