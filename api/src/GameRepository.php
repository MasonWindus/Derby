<?php

declare(strict_types=1);

namespace Derby;

final class GameRepository
{
    private string $dataDir;

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? dirname(__DIR__) . '/data/games';
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0777, true);
        }
    }

    public function create(array $game): void
    {
        $this->save($game);
    }

    public function find(string $gameId): ?array
    {
        $path = $this->pathFor($gameId);
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $game = json_decode($json, true);
        return is_array($game) ? $game : null;
    }

    public function save(array $game): void
    {
        $path = $this->pathFor($game['id']);
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($game, JSON_PRETTY_PRINT));
        rename($tmp, $path);
    }

    public function withLock(string $gameId, callable $callback): mixed
    {
        $lockFile = $this->pathFor($gameId) . '.lock';
        $fh = fopen($lockFile, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('Unable to open lock file');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire game lock');
            }

            $game = $this->find($gameId);
            if ($game === null) {
                throw new \RuntimeException('Game not found');
            }

            $result = $callback($game);
            $this->save($game);
            flock($fh, LOCK_UN);
            fclose($fh);

            return $result;
        } catch (\Throwable $e) {
            flock($fh, LOCK_UN);
            fclose($fh);
            throw $e;
        }
    }

    public function idExists(string $gameId): bool
    {
        return is_file($this->pathFor($gameId));
    }

    private function pathFor(string $gameId): string
    {
        return $this->dataDir . '/' . $gameId . '.json';
    }
}
