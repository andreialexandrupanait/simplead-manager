<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FormCheck;
use App\Models\Site;
use App\Models\SitePlugin;

/**
 * Faza 5.1 — contact-form deliverability self-test (owner-gated, on-demand).
 *
 * FAIL-SAFE. A real form submit on a client site could push a fake contact into
 * their CRM / Zapier / Mailchimp. So the ONLY plugins ever submitted are those
 * with a KNOWN suppression path on the connector side (WPForms, Gravity Forms,
 * Ninja Forms, MC4WP). Detection is done from the already-synced SitePlugin rows
 * (slugs) — no new detection code — and the allowlist is the authority for
 * `supported`. Anything else is reported {supported:false} and NEVER submitted.
 *
 * SPEC §5.4 asks for a real weekly submission, and CheckContactForms now provides
 * it. The fail-safe above is unchanged and is what makes a schedule acceptable:
 * an unsupported plugin is refused before any submit, so the weekly run cannot
 * reach a site whose integrations we cannot switch off.
 */
class ContactFormChecker
{
    /**
     * Allowlist: SitePlugin slug => display name. These are the ONLY plugins
     * with a proven integration-suppression path, hence the only supported ones.
     */
    public const SUPPORTED_PLUGINS = [
        'wpforms-lite' => 'WPForms',
        'wpforms' => 'WPForms',
        'gravityforms' => 'Gravity Forms',
        'ninja-forms' => 'Ninja Forms',
        'mailchimp-for-wp' => 'MC4WP',
    ];

    /**
     * Known form plugins that are NOT supported — recognised only so we can name
     * them in a {supported:false} result. Never submitted.
     */
    public const UNSUPPORTED_KNOWN_PLUGINS = [
        'contact-form-7' => 'Contact Form 7',
        'formidable' => 'Formidable Forms',
        'forminator' => 'Forminator',
        'fluentform' => 'Fluent Forms',
    ];

    public function __construct(
        private readonly WordPressApiServiceFactory $apiFactory,
    ) {}

    /**
     * Detect the site's form plugin from SitePlugin slugs (active plugins only).
     *
     * @return array{slug: string, plugin: string, supported: bool}|null
     */
    public function detectFormPlugin(Site $site): ?array
    {
        $active = $site->sitePlugins()
            ->where('is_active', true)
            ->get(['slug', 'name']);

        foreach ($active as $plugin) {
            if (isset(self::SUPPORTED_PLUGINS[$plugin->slug])) {
                return [
                    'slug' => $plugin->slug,
                    'plugin' => self::SUPPORTED_PLUGINS[$plugin->slug],
                    'supported' => true,
                ];
            }
        }

        foreach ($active as $plugin) {
            if (isset(self::UNSUPPORTED_KNOWN_PLUGINS[$plugin->slug])) {
                return [
                    'slug' => $plugin->slug,
                    'plugin' => self::UNSUPPORTED_KNOWN_PLUGINS[$plugin->slug],
                    'supported' => false,
                ];
            }
        }

        return null;
    }

    /**
     * Report which form plugin is present and whether it is supported. SAFE:
     * never submits. `supported` is decided by the Manager allowlist (from
     * SitePlugin) — the fail-safe authority. When a SUPPORTED plugin is present
     * we additionally probe the connector's safe /form-test/capability endpoint
     * for on-host confirmation, but the connector can never flip an unsupported
     * plugin to supported.
     *
     * @return array{form_plugin: ?string, supported: bool, connector: ?array<string,mixed>, forms: array<int, array{id: string, title: string}>}
     */
    public function capability(Site $site): array
    {
        $detected = $this->detectFormPlugin($site);
        $supported = $detected !== null && $detected['supported'];

        $connector = null;
        // Only probe the client site when we already know it is a supported
        // plugin — never touch an unsupported site, not even for a safe probe.
        if ($supported) {
            try {
                $response = $this->apiFactory->make($site)
                    ->request('GET', '/form-test/capability', [], [], 15);

                if ($response->successful()) {
                    $connector = $response->json();
                }
            } catch (\Throwable) {
                // Connector unreachable is non-fatal: local allowlist stays
                // authoritative for `supported`.
            }
        }

        $result = [
            'form_plugin' => $detected['plugin'] ?? null,
            'supported' => $supported,
            'connector' => $connector,
            // The site's forms, so an operator can say WHICH one is the contact
            // form. Empty on a connector older than 2.22.0, which simply means no
            // choice is offered.
            'forms' => is_array($connector['forms'] ?? null) ? $connector['forms'] : [],
        ];

        $this->recordCheck($site, $result['form_plugin'], $supported, [
            'status' => $supported ? 'capability_supported' : 'capability_unsupported',
        ]);

        return $result;
    }

    /**
     * Run the REAL, gated form test. Calls the connector's POST /form-test/run
     * ONLY when capability() reports supported. For an unsupported (or unknown)
     * plugin it refuses WITHOUT calling /run — the core fail-safe.
     *
     * NOT scheduled anywhere; invoked strictly on explicit operator command.
     *
     * @return array<string,mixed>
     *
     * @throws \RuntimeException when the plugin is not in the allowlist.
     */
    public function runGatedTest(Site $site): array
    {
        $capability = $this->capability($site);

        if (! $capability['supported']) {
            $this->recordCheck($site, $capability['form_plugin'], false, [
                'status' => 'refused_unsupported',
            ]);

            throw new \RuntimeException(
                'Contact-form test refused: '
                .($capability['form_plugin'] ?? 'no known form plugin')
                .' is not in the suppression allowlist. No submit was performed.'
            );
        }

        $deliverTo = $this->deliveryAddress($site);

        // Only ask for the redirect where the connector understands it. On an older
        // build the extra parameters are ignored and the notification is aborted as
        // before — the test still runs, delivery just cannot be confirmed.
        $payload = [];
        if ($deliverTo !== null && $site->connectorAtLeast((string) config('monitoring.form_test.min_connector_version', '2.21.0'))) {
            $payload = [
                'deliver_to' => $deliverTo,
                'test_name' => (string) config('monitoring.form_test.name', 'SimpleAD TEST'),
            ];
        }

        // Target a specific form where one was chosen and the connector can honour
        // it. Otherwise the connector submits to the first form it finds, which on
        // a site with a newsletter next to a contact form is a coin toss.
        if (filled($site->form_test_form_id)
            && $site->connectorAtLeast((string) config('monitoring.form_test.min_form_choice_version', '2.22.0'))) {
            $payload['form_id'] = (string) $site->form_test_form_id;
        }

        $response = $this->apiFactory->make($site)
            ->request('POST', '/form-test/run', $payload, [], 120);

        $data = $response->successful() ? ($response->json() ?? []) : [];

        $submitted = (bool) ($data['submitted'] ?? false);
        $suppression = (bool) ($data['suppression_confirmed'] ?? false);

        $this->recordCheck($site, $capability['form_plugin'], true, [
            'status' => $submitted ? 'submitted' : 'run_no_submit',
            'suppression_confirmed' => $suppression,
            'submitted_at' => $submitted ? now() : null,
            // Delivery is confirmed separately, by looking in the mailbox the
            // notification was redirected to. Clear any earlier verdict so a stale
            // "verified" from last week cannot be read as covering this run.
            'delivery_verified' => false,
            'delivered_to' => $data['delivered_to'] ?? $deliverTo,
        ]);

        return $data;
    }

    /**
     * Where this site's test notification should be delivered: the site's own
     * override (SPEC §9's per-site profile field) falling back to the global
     * address. Null means no address is configured anywhere, in which case the
     * connector keeps aborting the mail as it did before 2.21.0.
     */
    public function deliveryAddress(Site $site): ?string
    {
        $address = $site->form_test_email ?: config('monitoring.form_test.deliver_to');

        $address = is_string($address) ? trim($address) : '';

        return $address !== '' ? $address : null;
    }

    /**
     * Upsert the latest FormCheck row for a site.
     *
     * @param  array<string,mixed>  $extra
     */
    private function recordCheck(Site $site, ?string $formPlugin, bool $supported, array $extra = []): FormCheck
    {
        return FormCheck::updateOrCreate(
            ['site_id' => $site->id],
            array_merge([
                'form_plugin' => $formPlugin,
                'supported' => $supported,
                'checked_at' => now(),
            ], $extra),
        );
    }
}
