<?php

declare(strict_types=1);

namespace Shopper\Core\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Shopper\Core\CoreServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CoreServiceProvider::class];
    }
}
