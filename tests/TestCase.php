<?php

namespace Tests;

use App\Services\EunoService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    /**
     * Partial EunoService fake that still runs the real constructor (Laravel's partialMock()
     * skips it, leaving the API/web URLs unset). Methods not stubbed keep their real behaviour.
     */
    protected function fakeEuno(callable $stub): MockInterface
    {
        $mock = Mockery::mock(EunoService::class, [])->makePartial();
        $stub($mock);
        $this->app->instance(EunoService::class, $mock);

        return $mock;
    }
}
