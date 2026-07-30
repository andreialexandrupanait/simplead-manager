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
 * This service is NOT scheduled anywhere. runGatedTest() runs only when an
 * operator explicitly invokes it, and only after capability() gates it.
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
     * @return array{form_plugin: ?string, supported: bool, connector: ?array<string,mixed>}
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

        $response = $this->apiFactory->make($site)
            ->request('POST', '/form-test/run', [], [], 120);

        $data = $response->successful() ? ($response->json() ?? []) : [];

        $submitted = (bool) ($data['submitted'] ?? false);
        $suppression = (bool) ($data['suppression_confirmed'] ?? false);

        $this->recordCheck($site, $capability['form_plugin'], true, [
            'status' => $submitted ? 'submitted' : 'run_no_submit',
            'suppression_confirmed' => $suppression,
            'submitted_at' => $submitted ? now() : null,
        ]);

        return $data;
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
