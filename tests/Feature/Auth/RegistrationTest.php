<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registration_page_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    #[Test]
    public function new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
        $this->assertAuthenticated();
    }

    #[Test]
    public function registration_requires_name(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => '',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertGuest();
    }

    #[Test]
    public function duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->from('/register')->post('/register', [
            'name' => 'Test User',
            'email' => 'taken@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertGuest();
    }
}
