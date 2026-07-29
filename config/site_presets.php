<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| WordPress presets — the curated "Standard SimpleAD" package (SPEC §13)
|--------------------------------------------------------------------------
| The 10 settings surfaced from the connector's ~55 tweaks. `auto` is applied
| to every site at connection; `woo` is applied only where WooCommerce is
| detected. The three on-demand tools (media replace, hide plugin notices,
| admin branding) are intentionally NOT here — they are applied per request.
|
| Each entry maps to SiteTweaksSettingsService::VALID_SETTING_KEYS
| (category => setting_key). `essential` marks a setting that must not be off
| (SPEC: without "disable_all_updates" the site self-updates and the Manager
| loses control).
*/

return [
    'standard_simplead' => [
        'label' => 'Standard SimpleAD',

        // Applied on all sites at connection.
        'auto' => [
            'site_control' => [
                'disable_all_updates' => ['enabled' => true, 'essential' => true],
            ],
            'performance' => [
                // Standard head cleanup (safe everywhere).
                'disable_emojis' => ['enabled' => true],
                'disable_rsd_link' => ['enabled' => true],
                'disable_wlw_manifest' => ['enabled' => true],
                'disable_shortlinks' => ['enabled' => true],
                'disable_rest_api_links' => ['enabled' => true],
                'disable_generator_tag' => ['enabled' => true],
                'disable_dns_prefetch' => ['enabled' => true],
                'disable_self_pingbacks' => ['enabled' => true],
                'disable_dashicons' => ['enabled' => true],
                // Load-reducing controls.
                'heartbeat_control' => ['enabled' => true],
                'revisions_control' => ['enabled' => true],
                'image_upload_control' => ['enabled' => true],
            ],
        ],

        // Applied only where WooCommerce is detected (cart fragments off +
        // Woo scripts off on non-shop pages — the connector bundles both).
        'woo' => [
            'performance' => [
                'optimize_woocommerce' => ['enabled' => true],
            ],
        ],
    ],
];
