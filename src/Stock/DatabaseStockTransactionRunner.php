<?php

declare(strict_types=1);

namespace Shopper\Core\Stock;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Shopper\Core\Contracts\StockTransactionRunner;
use Throwable;

/**
 * The default runner: a plain database transaction. retry() repeats the
 * command only from the outermost level and only on a lock wait timeout
 * (1205), a deadlock (1213) or a stale snapshot (1020); a nested command
 * runs once and lets the failure reach the outer boundary.
 */
class DatabaseStockTransactionRunner implements StockTransactionRunner
{
    /** @var array<int, int> */
    protected const array RETRYABLE_ERRORS = [1205, 1213, 1020];

    public function run(callable $callback): mixed
    {
        return $this->connection()->transaction(Closure::fromCallable($callback));
    }

    public function retry(Closure $command): mixed
    {
        RepeatableClosure::assert($command);

        $connection = $this->connection();

        if ($connection->transactionLevel() > 0) {
            return $this->attempt($connection, $command);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attempt($connection, $command);
            } catch (QueryException|DeadlockException $exception) {
                if ($attempt >= $this->attempts() || ! $this->isRetryable($exception)) {
                    throw $exception;
                }
            }
        }
    }

    protected function attempt(ConnectionInterface $connection, Closure $command): mixed
    {
        return $connection->transaction($command);
    }

    protected function attempts(): int
    {
        return 1;
    }

    /**
     * A conflict raised inside a nested transaction reaches the boundary as
     * a DeadlockException wrapping the original query exception.
     */
    protected function isRetryable(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof QueryException || $current instanceof PDOException) {
                if (in_array((int) ($current->errorInfo[1] ?? 0), static::RETRYABLE_ERRORS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}
