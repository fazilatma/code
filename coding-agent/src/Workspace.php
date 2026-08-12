<?php

declare(strict_types=1);

namespace Bonsai;

use InvalidArgumentException;
use RuntimeException;

final class Workspace
{
    private string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false) {
            if (!mkdir($root, 0775, true) && !is_dir($root)) {
                throw new RuntimeException("Cannot create workspace: {$root}");
            }
            $real = realpath($root);
        }
        if ($real === false) {
            throw new RuntimeException("Invalid workspace: {$root}");
        }
        $this->root = $real;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function resolve(string $path, bool $mustExist = false): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        if ($path === '' || $path === '.') {
            return $this->root;
        }
        if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new InvalidArgumentException('Path traversal is not allowed');
        }

        $full = $this->root . '/' . $path;
        if ($mustExist) {
            $real = realpath($full);
            if ($real === false) {
                throw new InvalidArgumentException("Not found: {$path}");
            }
            $this->assertInside($real);
            return $real;
        }

        $parent = dirname($full);
        if (!is_dir($parent)) {
            if (!mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException("Cannot create directory: {$parent}");
            }
        }
        $parentReal = realpath($parent);
        if ($parentReal === false) {
            throw new RuntimeException("Invalid parent path for {$path}");
        }
        $this->assertInside($parentReal);
        return $parentReal . '/' . basename($full);
    }

    public function read(string $path): string
    {
        $full = $this->resolve($path, true);
        if (!is_file($full)) {
            throw new InvalidArgumentException("Not a file: {$path}");
        }
        $data = file_get_contents($full);
        if ($data === false) {
            throw new RuntimeException("Failed to read {$path}");
        }
        return $data;
    }

    public function write(string $path, string $content): string
    {
        $full = $this->resolve($path, false);
        $ok = file_put_contents($full, $content, LOCK_EX);
        if ($ok === false) {
            throw new RuntimeException("Failed to write {$path}");
        }
        return $this->relative($full);
    }

    public function delete(string $path): void
    {
        $full = $this->resolve($path, true);
        if (is_dir($full)) {
            throw new InvalidArgumentException('Refusing to delete a directory');
        }
        if (!unlink($full)) {
            throw new RuntimeException("Failed to delete {$path}");
        }
    }

    /**
     * @return list<array{path:string,type:string,size:int,modified:int}>
     */
    public function list(string $path = '.', int $depth = 4): array
    {
        $full = $this->resolve($path === '' ? '.' : $path, true);
        $out = [];
        $this->walk($full, $out, 0, $depth);
        usort($out, static fn ($a, $b) => [$a['type'] !== 'dir', $a['path']] <=> [$b['type'] !== 'dir', $b['path']]);
        return $out;
    }

    /**
     * @return list<array{path:string,line:int,text:string}>
     */
    public function search(string $query, string $path = '.', ?string $glob = null, int $limit = 80): array
    {
        $full = $this->resolve($path === '' ? '.' : $path, true);
        $hits = [];
        $this->grep($full, $query, $glob, $hits, $limit);
        return $hits;
    }

    public function relative(string $abs): string
    {
        $abs = str_replace('\\', '/', $abs);
        $root = str_replace('\\', '/', $this->root);
        if (str_starts_with($abs, $root)) {
            $rel = ltrim(substr($abs, strlen($root)), '/');
            return $rel === '' ? '.' : $rel;
        }
        return $abs;
    }

    /**
     * @param list<array{path:string,type:string,size:int,modified:int}> $out
     */
    private function walk(string $dir, array &$out, int $level, int $maxDepth): void
    {
        if ($level > $maxDepth) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name === '.gitkeep') {
                continue;
            }
            $full = $dir . '/' . $name;
            $rel = $this->relative($full);
            $isDir = is_dir($full);
            $out[] = [
                'path' => $rel,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? 0 : (int) filesize($full),
                'modified' => (int) filemtime($full),
            ];
            if ($isDir) {
                $this->walk($full, $out, $level + 1, $maxDepth);
            }
        }
    }

    /**
     * @param list<array{path:string,line:int,text:string}> $hits
     */
    private function grep(string $dir, string $query, ?string $glob, array &$hits, int $limit): void
    {
        if (count($hits) >= $limit) {
            return;
        }
        $entries = @scandir($dir) ?: [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_dir($full)) {
                $this->grep($full, $query, $glob, $hits, $limit);
                continue;
            }
            if ($glob !== null && $glob !== '' && !$this->matchGlob($name, $glob)) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['php', 'py', 'js', 'ts', 'json', 'md', 'txt', 'css', 'html', 'sh', 'csv'], true)) {
                continue;
            }
            $fh = fopen($full, 'r');
            if ($fh === false) {
                continue;
            }
            $lineNo = 0;
            while (($line = fgets($fh)) !== false) {
                $lineNo++;
                if (stripos($line, $query) !== false) {
                    $hits[] = [
                        'path' => $this->relative($full),
                        'line' => $lineNo,
                        'text' => rtrim($line, "\r\n"),
                    ];
                    if (count($hits) >= $limit) {
                        fclose($fh);
                        return;
                    }
                }
            }
            fclose($fh);
        }
    }

    private function matchGlob(string $name, string $glob): bool
    {
        return fnmatch($glob, $name);
    }

    private function assertInside(string $abs): void
    {
        $root = $this->root;
        if ($abs !== $root && !str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Path escapes the workspace');
        }
    }
}
