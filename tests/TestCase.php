<?php

namespace Kayedspace\Erpnext\Tests;

use Kayedspace\Erpnext\ErpnextServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots a minimal Laravel application with only this package registered, so the suite
 * proves the package works on its own rather than leaning on a host app.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ErpnextServiceProvider::class];
    }
}
