<?php

declare(strict_types=1);

namespace SimpleDB\Contracts;

/**
 * Marks a StorageAdapter as a decorator that wraps another adapter.
 *
 * QueryBuilder uses this to peel back decorator layers (e.g. ApcuCacheAdapter)
 * until it reaches an adapter that may implement NativeQueryInterface.
 */
interface DecoratorInterface
{
    public function getInnerAdapter(): StorageInterface;
}
