<?php

declare(strict_types=1);

use Shopper\Core\Stock\DatabaseStockTransactionRunner;

return [

    /*
    |--------------------------------------------------------------------------
    | Stock Transaction Runner
    |--------------------------------------------------------------------------
    |
    | Every transaction that leads to the stock ledger is opened by this
    | runner: run() wraps a callable in one transaction without a retry,
    | retry() is the repeatable command boundary that receives a static
    | closure over immutable input. The default runner opens a plain
    | database transaction with a single attempt for both.
    |
    */

    'transaction_runner' => DatabaseStockTransactionRunner::class,

    /*
    |--------------------------------------------------------------------------
    | Reconcile Fix
    |--------------------------------------------------------------------------
    |
    | When false, `shopper:stock:reconcile --fix` is refused: the snapshot is
    | then never rewritten without a ledger movement. The report mode stays.
    |
    */

    'reconcile_fix' => true,

];
