<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SendDailyDigest;
use App\Mail\DailyDigestMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P3-29: the daily digest previously force-mailed every user. It must now honour
 * each user's digest_enabled preference — opted-out users are never mailed.
 */
class SendDailyDigestOptInTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_respects_the_per_user_opt_out(): void
    {
        Mail::fake();

        $optedIn = User::factory()->create(['digest_enabled' => true]);
        $optedOut = User::factory()->create(['digest_enabled' => false]);

        (new SendDailyDigest)->handle();

        Mail::assertQueued(DailyDigestMail::class, fn ($mail) => $mail->hasTo($optedIn->email));
        Mail::assertNotQueued(DailyDigestMail::class, fn ($mail) => $mail->hasTo($optedOut->email));
    }
}
