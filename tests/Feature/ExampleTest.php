<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_entry_page_contains_the_vue_mount_point(): void
    {
        $response = $this->get('/');

        $response
            ->assertStatus(200)
            ->assertSee('id="app"', false);
    }
}
