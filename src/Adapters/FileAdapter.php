<?php

declare(strict_types=1);

namespace SimpleDB\Adapters;

use SimpleDB\Contracts\StorageInterface;
use SimpleDB\Exceptions\StorageException;

class FileAdapter implements StorageInterface
{
    private readonly string $storageDir;

    public function __construct(string $storageDir)
    {
        $realDir = realpath($storageDir);

        if ($realDir === false) {
            set_error_handler(static fn(): bool => true);
            try {
                $created = mkdir($storageDir, 0755, true);
            } finally {
                restore_error_handler();
            }

            if (!$created && !is_dir($storageDir)) {
                throw new StorageException("Cannot create storage directory: {$storageDir}");
            }

            $realDir = realpath($storageDir);
        }

        if ($realDir === false || !is_dir($realDir)) {
            throw new StorageException("Storage directory is not valid: {$storageDir}");
        }

        $this->storageDir = $realDir;
    }

    /**
     * Sanitise a collection name or document ID.
     * Only [a-zA-Z0-9_-] characters are permitted.
     *
     * @throws StorageException on invalid input
     */
    private function sanitise(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            throw new StorageException(
                "Invalid name '{$value}': only alphanumeric characters, hyphens and underscores are allowed."
            );
        }

        return $value;
    }

    private function collectionPath(string $collection): string
    {
        $safe = $this->sanitise($collection);
        $path = $this->storageDir . DIRECTORY_SEPARATOR . $safe;

        if (!is_dir($path)) {
            set_error_handler(static fn(): bool => true);
            try {
                $created = mkdir($path, 0755, true);
            } finally {
                restore_error_handler();
            }

            if (!$created && !is_dir($path)) {
                throw new StorageException("Cannot create collection directory: {$path}");
            }
        }

        return $path;
    }

    private function filePath(string $collection, string $id): string
    {
        $safe = $this->sanitise($id);

        return $this->collectionPath($collection) . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    public function read(string $collection, string $id): array|null
    {
        $path = $this->filePath($collection, $id);

        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new StorageException("Failed to read document '{$id}' from collection '{$collection}'.");
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            throw new StorageException("Corrupt document '{$id}' in collection '{$collection}': invalid JSON.");
        }

        return $data;
    }

    public function readAll(string $collection): array
    {
        $ids    = $this->listIds($collection);
        $output = [];

        foreach ($ids as $id) {
            $doc = $this->read($collection, $id);
            if ($doc !== null) {
                $output[$id] = $doc;
            }
        }

        return $output;
    }

    public function write(string $collection, string $id, array $data): void
    {
        $path    = $this->filePath($collection, $id);
        $dir     = dirname($path);
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $tmp = tempnam($dir, '.tmp_');

        if ($tmp === false) {
            throw new StorageException("Cannot create temp file in '{$dir}'.");
        }

        try {
            $handle = fopen($tmp, 'wb');

            if ($handle === false) {
                throw new StorageException("Cannot open temp file for writing: {$tmp}");
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    throw new StorageException("Cannot acquire exclusive lock on: {$tmp}");
                }

                $written = fwrite($handle, $content);

                if ($written === false || $written !== strlen($content)) {
                    throw new StorageException("Failed to write all bytes to: {$tmp}");
                }

                fflush($handle);
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
            }

            if (!rename($tmp, $path)) {
                throw new StorageException("Atomic rename failed: {$tmp} -> {$path}");
            }
        } catch (StorageException $e) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            throw $e;
        }
    }

    public function delete(string $collection, string $id): void
    {
        $path = $this->filePath($collection, $id);

        if (!file_exists($path)) {
            return;
        }

        if (!unlink($path)) {
            throw new StorageException("Failed to delete document '{$id}' from collection '{$collection}'.");
        }
    }

    public function exists(string $collection, string $id): bool
    {
        try {
            $path = $this->filePath($collection, $id);
        } catch (StorageException) {
            return false;
        }

        return file_exists($path);
    }

    public function listIds(string $collection): array
    {
        $dir = $this->collectionPath($collection);
        $ids = [];

        $entries = scandir($dir);

        if ($entries === false) {
            throw new StorageException("Cannot read collection directory: {$dir}");
        }

        foreach ($entries as $entry) {
            if (str_ends_with($entry, '.json') && !str_starts_with($entry, '.')) {
                $ids[] = substr($entry, 0, -5);
            }
        }

        return $ids;
    }

    public function timestamp(string $collection, string $id): int|null
    {
        try {
            $path = $this->filePath($collection, $id);
        } catch (StorageException) {
            return null;
        }

        if (!file_exists($path)) {
            return null;
        }

        $time = filemtime($path);

        return $time !== false ? $time : null;
    }
}
