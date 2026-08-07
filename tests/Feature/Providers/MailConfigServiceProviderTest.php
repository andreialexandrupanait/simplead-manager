<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Providers\MailConfigServiceProvider;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The database overrides must not be applied while `config:cache` is building
 * the cached config file, or they get frozen into it — and a setting that is
 * later removed keeps taking effect from the frozen copy until the container
 * restarts.
 */
class MailConfigServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): void
    {
        $provider = new MailConfigServiceProvider($this->app);
        $provider->boot();

        // booted() fires immediately on an already-booted application.
        $this->app->booted(fn () => null);
    }

    public function test_it_applies_the_database_overrides_in_the_normal_case(): void
    {
        app(SettingsService::class)->set('mail.username', 'from-the-database@example.com', 'mail');
        config(['mail.mailers.smtp.username' => 'from-the-environment@example.com']);

        $this->boot();

        $this->assertSame('from-the-database@example.com', config('mail.mailers.smtp.username'));
    }

    public function test_it_leaves_the_config_alone_while_the_cache_is_being_built(): void
    {
        app(SettingsService::class)->set('mail.username', 'from-the-database@example.com', 'mail');
        config(['mail.mailers.smtp.username' => 'from-the-environment@example.com']);

        $original = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'config:cache'];

        try {
            $this->boot();
        } finally {
            if ($original === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $original;
            }
        }

        // What gets written to the cache file is the environment's value, which
        // the provider then layers the database on top of at runtime.
        $this->assertSame('from-the-environment@example.com', config('mail.mailers.smtp.username'));
    }
}
