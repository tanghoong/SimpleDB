<?php

declare(strict_types=1);

namespace SimpleDB\Contracts;

interface StorageInterface
{
    public function read(string $collection, string $id): array|null;

    public function readAll(string $collection): array;

    public function write(string $collection, string $id, array $data): void;

    public function delete(string $collection, string $id): void;

    public function exists(string $collection, string $id): bool;

    public function listIds(string $collection): array;

    public function timestamp(string $collection, string $id): int|null;
}
