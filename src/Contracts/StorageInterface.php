<?php

declare(strict_types=1);

namespace SimpleDB\Contracts;

interface StorageInterface
{
    public function read(string $collection, string $id): array|null;

    public function readAll(string $collection): array;

    /** @return \Generator<string, array> Yields id => document pairs without loading all documents into memory */
    public function stream(string $collection): \Generator;

    public function write(string $collection, string $id, array $data): void;

    /** @param array<string, array> $documents Associative array of id => data pairs */
    public function batchWrite(string $collection, array $documents): void;

    public function delete(string $collection, string $id): void;

    public function exists(string $collection, string $id): bool;

    public function listIds(string $collection): array;

    public function count(string $collection): int;

    public function timestamp(string $collection, string $id): int|null;
}
