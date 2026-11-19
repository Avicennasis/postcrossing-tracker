<?php

namespace Tests\Feature;

use App\Livewire\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.recaptcha_site_key' => null, 'app.recaptcha_secret_key' => null]);
        Http::preventStrayRequests();
        User::create(['username' => 'owner@example.test', 'password' => 'correct horse']);
    }

    public function test_without_recaptcha_keys_there_is_no_widget_and_login_works(): void
    {
        $this->withoutVite()->get(route('login'))->assertOk()->assertDontSee('data-sitekey', false)->assertDontSee('recaptcha/api.js', false);

        Livewire::test(Login::class)
            ->set('username', 'owner@example.test')
            ->set('password', 'correct horse')
            ->call('authenticate')
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Auth::check());
    }

    public function test_repeated_failures_lock_the_username_out(): void
    {
        for ($i = 0; $i < Login::MAX_ATTEMPTS; $i++) {
            Livewire::test(Login::class)->set('username', 'owner@example.test')->set('password', 'wrong')
                ->call('authenticate')->assertSet('error', 'Access denied. Please check your credentials.');
        }

        // Even the right password is refused during the lockout
        Livewire::test(Login::class)->set('username', 'OWNER@example.test')->set('password', 'correct horse')
            ->call('authenticate')->assertNoRedirect();

        $this->assertFalse(Auth::check());
    }

    public function test_recaptcha_token_is_verified_server_side_when_a_secret_is_set(): void
    {
        config(['app.recaptcha_site_key' => 'site', 'app.recaptcha_secret_key' => 'secret']);
        Http::fake(['www.google.com/recaptcha/api/siteverify' => Http::sequence()
            ->push(['success' => false])
            ->push(['success' => true])]);

        Livewire::test(Login::class)->set('username', 'owner@example.test')->set('password', 'correct horse')
            ->set('recaptchaToken', 'forged')->call('authenticate')
            ->assertSet('error', 'Please complete the reCAPTCHA verification.')->assertNoRedirect();

        Livewire::test(Login::class)->set('username', 'owner@example.test')->set('password', 'correct horse')
            ->set('recaptchaToken', 'real')->call('authenticate')->assertRedirect(route('dashboard'));

        Http::assertSent(fn (Request $r) => $r['secret'] === 'secret' && $r['response'] === 'real');
    }
}
