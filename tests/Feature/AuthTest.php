<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Sessions;
use App\Services\Users;
use Tests\TestCase;

class AuthTest extends TestCase
{
    private function login(string $email, string $password)
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_login_sets_a_session_cookie_and_returns_the_user(): void
    {
        Users::addOrReset('Pat@Example.test', 'Pat', 'password1', isAdmin: false);

        $response = $this->login('  pat@EXAMPLE.test ', 'password1')->assertOk()
            ->assertJson(['email' => 'Pat@Example.test', 'full_name' => 'Pat', 'is_admin' => false]);

        $cookie = $response->getCookie(Sessions::COOKIE, decrypt: false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->withCredentials()->withUnencryptedCookies([Sessions::COOKIE => $cookie->getValue()])
            ->getJson('/api/auth/me')->assertOk()->assertJson(['full_name' => 'Pat']);
    }

    public function test_bad_credentials_and_inactive_users_are_401(): void
    {
        Users::addOrReset('pat@example.test', 'Pat', 'password1', isAdmin: false);
        $this->login('pat@example.test', 'wrong-password')->assertUnauthorized();
        $this->login('nobody@example.test', 'password1')->assertUnauthorized();

        User::query()->where('email', 'pat@example.test')->update(['is_active' => false]);
        $this->login('pat@example.test', 'password1')->assertUnauthorized();
    }

    public function test_empty_fields_and_bad_json_are_400(): void
    {
        $this->login('', 'password1')->assertBadRequest()->assertJsonStructure(['error']);
        $this->postJson('/api/auth/login', ['email' => ['x'], 'password' => 'x'])->assertBadRequest();
        $this->call('POST', '/api/auth/login', content: '{bad')->assertBadRequest();
    }

    public function test_deactivation_ends_sessions_immediately(): void
    {
        $cookies = $this->loginAs('pat@example.test', isAdmin: false);
        $this->withCredentials()->withUnencryptedCookies($cookies)->getJson('/api/auth/me')->assertOk();

        User::query()->where('email', 'pat@example.test')->update(['is_active' => false]);
        $this->withCredentials()->withUnencryptedCookies($cookies)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_session_and_is_idempotent(): void
    {
        $cookies = $this->loginAs();
        $this->withCredentials()->withUnencryptedCookies($cookies)->postJson('/api/auth/logout')->assertNoContent();
        $this->withCredentials()->withUnencryptedCookies($cookies)->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/logout')->assertNoContent();
    }

    public function test_unknown_api_paths_are_401_then_404(): void
    {
        $this->getJson('/api/no-such-thing')->assertUnauthorized();
        $this->deleteJson('/api/auth/me')->assertUnauthorized();

        $cookies = $this->loginAs();
        $this->withCredentials()->withUnencryptedCookies($cookies)->getJson('/api/no-such-thing')
            ->assertNotFound()->assertJsonStructure(['error']);
        $this->withCredentials()->withUnencryptedCookies($cookies)->deleteJson('/api/auth/me')->assertNotFound();
    }
}
