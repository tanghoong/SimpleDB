<?php

declare(strict_types=1);

namespace SimpleDB\Adapters;

use SimpleDB\Contracts\StorageInterface;
use SimpleDB\Exceptions\StorageException;

class FileAdapter implements StorageInterface
{
    private readonly string $storageDir;

    /**
     * @param string $storageDir    Writable directory used as the storage root.
     * @param int    $maxDocumentSize  Maximum allowed JSON byte size per document (default 5 MiB).
     */
    public function __construct(
        string $storageDir,
        private readonly int $maxDocumentSize = 5 * 1024 * 1024,
    ) {
        $realDir = realpath($storageDir);

        if ($realDir === false) {
            set_error_handler(static fn(): bool => true);
            try {
                $created = mkdir($storageDir, 0750, true);
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

    /**
     * Verify that a resolved filesystem path stays within the storage root.
     * Defends against symlink-based escape attempts.
     *
     * @throws StorageException when the path escapes the storage root
     */
    private function verifyWithinRoot(string $resolvedPath): void
    {
        $prefix = $this->storageDir . DIRECTORY_SEPARATOR;

        if (!str_starts_with($resolvedPath . DIRECTORY_SEPARATOR, $prefix)) {
            throw new StorageException("Path escapes storage root: {$resolvedPath}");
        }
    }

    private function collectionPath(string $collection): string
    {
        $safe = $this->sanitise($collection);
        $path = $this->storageDir . DIRECTORY_SEPARATOR . $safe;

        if (!is_dir($path)) {
            set_error_handler(static fn(): bool => true);
            try {
                $created = mkdir($path, 0750, true);
            } finally {
                restore_error_handler();
            }

            if (!$created && !is_dir($path)) {
                throw new StorageException("Cannot create collection directory: {$path}");
            }
        }

        $resolved = realpath($path);

        if ($resolved === false) {
            throw new StorageException("Cannot resolve collection directory: {$path}");
        }

        $this->verifyWithinRoot($resolved);

        return $resolved;
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

    /** @return \Generator<string, array> */
    public function stream(string $collection): \Generator
    {
        foreach ($this->listIds($collection) as $id) {
            $doc = $this->read($collection, $id);
            if ($doc !== null) {
                yield $id => $doc;
            }
        }
    }

    public function write(string $collection, string $id, array $data): void
    {
        $path     = $this->filePath($collection, $id);
        $dir      = dirname($path);
        $lockPath = $path . '.lock';

        try {
            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StorageException(
                "Failed to encode document '{$id}' to JSON: " . $e->getMessage(),
                0,
                $e
            );
        }

        if (strlen($content) > $this->maxDocumentSize) {
            throw new StorageException(
                "Document '{$id}' exceeds the maximum allowed size of {$this->maxDocumentSize} bytes."
            );
        }

        // Acquire an exclusive lock on a deterministic per-document lock file so that
        // concurrent writers are serialised and the lock is held across the rename().
        $lockHandle = fopen($lockPath, 'c');

        if ($lockHandle === false) {
            throw new StorageException("Cannot open lock file: {$lockPath}");
        }

        $tmp = null;

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new StorageException("Cannot acquire exclusive lock on: {$lockPath}");
            }

            $tmp = tempnam($dir, '.tmp_');

            if ($tmp === false) {
                throw new StorageException("Cannot create temp file in '{$dir}'.");
            }

            $handle = fopen($tmp, 'wb');

            if ($handle === false) {
                throw new StorageException("Cannot open temp file for writing: {$tmp}");
            }

            try {
                $written = fwrite($handle, $content);

                if ($written === false || $written !== strlen($content)) {
                    throw new StorageException("Failed to write all bytes to: {$tmp}");
                }

                fflush($handle);
            } finally {
                fclose($handle);
            }

            if (!rename($tmp, $path)) {
                throw new StorageException("Atomic rename failed: {$tmp} -> {$path}");
            }

            $tmp = null; // rename succeeded; no temp-file cleanup needed
        } catch (\Throwable $e) {
            if ($tmp !== null && file_exists($tmp)) {
                unlink($tmp);
            }
            throw $e instanceof StorageException ? $e : new StorageException($e->getMessage(), 0, $e);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** @param array<string, array> $documents */
    public function batchWrite(string $collection, array $documents): void
    {
        foreach ($documents as $id => $data) {
            $this->write($collection, (string) $id, $data);
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

    public function count(string $collection): int
    {
        return count($this->listIds($collection));
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

        set_error_handler(static fn(): bool => true);
        try {
            $time = filemtime($path);
        } finally {
            restore_error_handler();
        }

        if ($time === false) {
            throw new StorageException(
                "Failed to read modification time of document '{$id}' in collection '{$collection}'."
            );
        }

        return $time;
    }
}
