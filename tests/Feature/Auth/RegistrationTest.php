<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled()
    {
        // Multi-tenant ERP: users are provisioned by an Owner via Settings > Team,
        // never self-registered. The register routes are removed; the app's
        // fallback sends a guest hitting the old URL to login, and no account
        // can be created through it.
        $this->get('/register')->assertRedirect('/login');

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
