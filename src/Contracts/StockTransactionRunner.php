<?php

declare(strict_types=1);

namespace Shopper\Core\Contracts;

use Closure;

interface StockTransactionRunner
{
    /**
     * Run the callable in one transaction, without a retry.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(callable $callback): mixed;

    /**
     * Run a repeatable command: a static closure over immutable input that
     * loads every model it touches by key inside each attempt.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $command
     * @return TReturn
     */
    public function retry(Closure $command): mixed;
}
