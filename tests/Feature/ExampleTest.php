<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_front_door_leads_to_the_dashboard_and_the_health_check_is_up(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/up')->assertOk();
    }
}
