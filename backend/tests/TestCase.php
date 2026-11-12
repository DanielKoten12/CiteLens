<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Any test that reaches the network without an explicit fake is a bug:
        // fail loudly instead of depending on external services being reachable.
        Http::preventStrayRequests();
    }
}
