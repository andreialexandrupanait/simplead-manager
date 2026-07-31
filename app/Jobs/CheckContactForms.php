<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\ContactFormChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SPEC §5.4: "Formulare de contact — trimitere reală săptămânală, cu marcaj, plus
 * verificare de livrare a emailului."
 *
 * ContactFormChecker was written, tested and then never called: it had zero
 * callers anywhere in the codebase, and said so in its own docblock. A contact
 * form that silently stopped delivering was exactly the failure nobody would
 * notice until a client asked why nobody had replied to them.
 *
 * Safety is inherited, not re-implemented. runGatedTest() refuses any form plugin
 * without a proven integration-suppression path, so this sweep simply cannot
 * touch a site whose CRM/Zapier/Mailchimp we are unable to switch off; those
 * sites are counted as skipped and left alone.
 */
class CheckContactForms implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    /**
     * @param  int|null  $siteId  Limit the run to one site. The weekly schedule passes
     *                            nothing; the "run now" button on a site passes its id.
     */
    public function __construct(
        public ?int $siteId = null,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'check-contact-forms'.($this->siteId !== null ? ':'.$this->siteId : '');
    }

    public function handle(ContactFormChecker $checker): void
    {
        if (! config('monitoring.form_test.enabled', true)) {
            Log::info('CheckContactForms skipped: disabled by config');

            return;
        }

        $tested = 0;
        $skipped = 0;

        Site::query()
            ->where('is_connected', true)
            // Opt-in, per site — but only for the scheduled fleet sweep. The plugin
            // fail-safe decides what is SAFE to test; this decides what someone
            // actually asked to be tested, and a scheduled real submission on a
            // client's live form is not something to inherit by being connected.
            //
            // A run aimed at one site is an explicit request, so it runs regardless
            // of the toggle — the same reasoning as the "run now" button.
            ->when($this->siteId === null, fn ($q) => $q->where('form_test_enabled', true))
            ->when($this->siteId !== null, fn ($q) => $q->whereKey($this->siteId))
            ->each(function (Site $site) use ($checker, &$tested, &$skipped): void {
                try {
                    $checker->runGatedTest($site);
                    $tested++;
                } catch (\Throwable $e) {
                    // The overwhelmingly common case is the deliberate refusal for an
                    // unsupported form plugin, which is the fail-safe working, not an
                    // error. Recorded at info level so a weekly sweep does not fill
                    // the log with warnings about sites behaving exactly as intended.
                    $skipped++;
                    Log::info('CheckContactForms: site skipped', [
                        'site_id' => $site->id,
                        'reason' => $e->getMessage(),
                    ]);
                }
            });

        Log::info('Contact-form sweep complete', [
            'tested' => $tested,
            'skipped' => $skipped,
        ]);
    }
}
