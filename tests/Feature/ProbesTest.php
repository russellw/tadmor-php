<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProbesTest extends TestCase
{
    public function test_health(): void
    {
        $this->get('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_ready(): void
    {
        $this->get('/readyz')->assertOk()->assertExactJson(['status' => 'ready']);
    }
}
