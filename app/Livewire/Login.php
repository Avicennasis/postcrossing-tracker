<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

class Login extends Component
{
    /** Failed attempts allowed per username + IP before a lockout */
    public const MAX_ATTEMPTS = 5;

    /** Lockout length in seconds */
    public const LOCKOUT_SECONDS = 300;

    public $username;

    public $password;

    public $recaptchaToken;

    public $error = '';

    public function mount()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
    }

    protected $rules = [
        'username' => 'required',
        'password' => 'required',
    ];

    /**
     * reCAPTCHA is used only when a site key is configured. Without one the
     * widget would never render and the Sign In button would stay disabled.
     */
    public function recaptchaEnabled(): bool
    {
        return filled(config('app.recaptcha_site_key'));
    }

    public function authenticate()
    {
        $this->validate();

        $key = 'login:'.Str::lower((string) $this->username).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->error = 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.';

            return;
        }

        if ($this->recaptchaEnabled() && ! $this->recaptchaPassed()) {
            $this->error = 'Please complete the reCAPTCHA verification.';

            return;
        }

        if (Auth::attempt(['username' => $this->username, 'password' => $this->password])) {
            RateLimiter::clear($key);
            session()->regenerate();

            return redirect()->route('dashboard');
        }

        RateLimiter::hit($key, self::LOCKOUT_SECONDS);
        $this->error = 'Access denied. Please check your credentials.';
    }

    /**
     * Verify the token with Google when a secret key is configured (a token
     * that is merely present proves nothing).
     */
    protected function recaptchaPassed(): bool
    {
        if (blank($this->recaptchaToken)) {
            return false;
        }

        $secret = config('app.recaptcha_secret_key');
        if (blank($secret)) {
            return true; // site key only: client-side check, as before
        }

        try {
            $response = Http::asForm()->timeout(10)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $this->recaptchaToken,
                'remoteip' => request()->ip(),
            ]);

            return $response->successful() && $response->json('success') === true;
        } catch (\Throwable $e) {
            Log::warning('reCAPTCHA verification failed: '.$e->getMessage());

            return false;
        }
    }

    public function render()
    {
        return view('livewire.login', ['recaptchaEnabled' => $this->recaptchaEnabled()])
            ->layout('components.layouts.app', ['title' => 'Manager Access - Private']);
    }
}
