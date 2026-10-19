<?php

declare(strict_types=1);

use Shopper\Core\Tests\StockTestCase;
use Shopper\Core\Tests\TestCase;

pest()->extend(TestCase::class)->in('CustomerModelKeyTest.php');
pest()->extend(StockTestCase::class)->in('Stock');

function recordingStockRunner(): Shopper\Core\Tests\Stubs\RecordingStockTransactionRunner
{
    $runner = new Shopper\Core\Tests\Stubs\RecordingStockTransactionRunner;

    app()->instance(Shopper\Core\Contracts\StockTransactionRunner::class, $runner);

    return $runner;
}
