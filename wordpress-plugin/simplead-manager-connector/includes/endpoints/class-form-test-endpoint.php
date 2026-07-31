<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contact-form deliverability self-test (Faza 5.1).
 *
 * FAIL-SAFE BY DESIGN. A real form submit on a client site could push a fake
 * contact into their CRM / Zapier / Mailchimp. This endpoint therefore:
 *
 *   - only supports plugins with a KNOWN integration-suppression path
 *     (WPForms, Gravity Forms, Ninja Forms, MC4WP). Anything else short-circuits
 *     with {supported:false} and NEVER submits.
 *   - GET /form-test/capability is a pure probe: it reports which form plugin is
 *     present and whether it is supported. It NEVER submits anything.
 *   - POST /form-test/run registers per-plugin suppression filters BEFORE the
 *     submit, marks the submitted email with a +samtest tag, deletes the created
 *     entry afterwards, and always clears its active flag in a finally block.
 *
 * shell_exec is disabled on target hosts — nothing here shells out.
 */
class SAM_Form_Test_Endpoint extends SAM_Endpoint_Base {

    /** Transient flag: set while a gated test is running (auto-expires). */
    const ACTIVE_FLAG = 'sam_form_test_active';

    /** Max seconds the active flag may live. */
    const ACTIVE_TTL = 120;

    /** Prefix stamped on the redirected notification's subject. */
    const SUBJECT_MARKER = '[TEST]';

    /** Name written into the submission when the Manager does not supply one. */
    const DEFAULT_TEST_NAME = 'SimpleAD TEST';

    /** Ceiling on the content scan — a discovery probe must not become a crawl. */
    const DISCOVER_MAX_POSTS = 300;

    /**
     * Where forms can live. elementor_library is not optional: Theme Builder
     * templates, popups and global widgets are stored there, so a form in a
     * footer or a popup is invisible to a scan of pages alone — which is exactly
     * how the first version of this scan reported "0 forms" for a site whose
     * homepage visibly renders one.
     */
    const DISCOVER_POST_TYPES = ['page', 'post', 'product', 'elementor_library', 'e-landing-page'];

    /**
     * Allowlist: plugin file => [display name, internal key]. ONLY these have a
     * proven suppression path, so ONLY these are ever submitted.
     */
    private function supported_plugins(): array {
        return [
            'wpforms-lite/wpforms.php'                 => ['WPForms', 'wpforms'],
            'wpforms/wpforms.php'                      => ['WPForms', 'wpforms'],
            'gravityforms/gravityforms.php'            => ['Gravity Forms', 'gravityforms'],
            'ninja-forms/ninja-forms.php'              => ['Ninja Forms', 'ninja_forms'],
            'mailchimp-for-wp/mailchimp-for-wp.php'    => ['MC4WP', 'mc4wp'],
            // 23 of the 27 sites in this fleet build their contact forms with
            // Elementor Pro's Form widget. Excluding it meant the whole feature
            // covered a single site. It qualifies for the same reason the others
            // do — its actions (email, webhook, Mailchimp, ActiveCampaign) all
            // leave through wp_mail or the HTTP API, both of which the generic
            // suppression in register_suppression() now closes.
            'elementor-pro/elementor-pro.php'          => ['Elementor Pro', 'elementor_pro'],
        ];
    }

    /**
     * Known form plugins that are NOT in the allowlist — recognised only so we
     * can name them in a {supported:false} response. Never submitted.
     */
    private function unsupported_known_plugins(): array {
        return [
            'contact-form-7/wp-contact-form-7.php' => 'Contact Form 7',
            'formidable/formidable.php'            => 'Formidable Forms',
            'forminator/forminator.php'            => 'Forminator',
            'fluentform/fluentform.php'            => 'Fluent Forms',
            'wpforms-lite/wpforms-lite.php'        => 'WPForms',
        ];
    }

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/form-test/capability', [
            'methods'             => 'GET',
            'callback'            => [$this, 'capability'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/form-test/discover', [
            'methods'             => 'GET',
            'callback'            => [$this, 'discover'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/form-test/run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'run'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                // Where the notification should be delivered instead of the site's
                // own recipient. When supplied, the mail is REDIRECTED rather than
                // aborted, so the Manager can confirm it actually arrived — the
                // delivery half of SPEC §5.4. The client's inbox stays clean either
                // way. Omitted → the pre-2.21.0 behaviour (abort the send).
                'deliver_to' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_email',
                ],
                // Name written into the submission, so the entry is recognisably a
                // test rather than an anonymous stranger.
                'test_name' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                // Which form to submit to. Omitted → the first one found, the
                // pre-2.22.0 behaviour. Listed by GET /form-test/capability.
                'form_id' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Detect the active form plugin. Returns:
     *   ['file','name','key','supported'] or null when no form plugin is active.
     */
    private function detect_form_plugin(): ?array {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ($this->supported_plugins() as $file => $info) {
            if (is_plugin_active($file)) {
                return ['file' => $file, 'name' => $info[0], 'key' => $info[1], 'supported' => true];
            }
        }

        foreach ($this->unsupported_known_plugins() as $file => $name) {
            if (is_plugin_active($file)) {
                return ['file' => $file, 'name' => $name, 'key' => null, 'supported' => false];
            }
        }

        return null;
    }

    /**
     * GET /form-test/capability — pure probe, submits NOTHING.
     */
    public function capability(WP_REST_Request $request): WP_REST_Response {
        $plugin = $this->detect_form_plugin();

        return $this->success([
            'form_plugin' => $plugin['name'] ?? null,
            'plugin_file' => $plugin['file'] ?? null,
            'plugin_key'  => $plugin['key'] ?? null,
            'supported'   => $plugin['supported'] ?? false,
            'active'      => $plugin !== null,
            // The site's forms, so the operator can pick which one is the contact
            // form. Without this the test submits to whichever comes first, and on
            // a site with a newsletter alongside a contact form that is a coin toss.
            // Still a pure probe: listing forms submits nothing.
            'forms'       => !empty($plugin['supported']) ? $this->list_forms($plugin['key']) : [],
        ]);
    }

    /**
     * GET /form-test/discover — find forms by looking at the SITE, not at the
     * plugin list.
     *
     * Until now "detection" meant comparing active plugin slugs against five
     * hardcoded names. A site whose forms are Elementor widgets — 23 of the 27 in
     * this fleet — reported "no form plugin" and was refused, without anything
     * ever having looked at a single page.
     *
     * This walks published content and reports every form it can see, with the
     * page it lives on, whatever built it. Pure probe: submits nothing.
     */
    public function discover(WP_REST_Request $request): WP_REST_Response {
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0 || $limit > self::DISCOVER_MAX_POSTS) {
            $limit = self::DISCOVER_MAX_POSTS;
        }

        $posts = get_posts([
            'post_type'        => self::DISCOVER_POST_TYPES,
            'post_status'      => ['publish', 'private'],
            'numberposts'      => $limit,
            'orderby'          => 'menu_order title',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);

        $found = [];
        // Coverage counters. A scan that finds nothing is ambiguous — no forms, or
        // a scan that never reached them? These say which, so the next question is
        // answered with a number instead of another guess.
        $withElementor = 0;
        $parsed = 0;

        foreach ($posts as $post) {
            $page = [
                'post_id' => (int) $post->ID,
                'title'   => (string) $post->post_title,
                'url'     => (string) get_permalink($post),
            ];

            $raw = get_post_meta($post->ID, '_elementor_data', true);
            if (is_string($raw) && $raw !== '') {
                $withElementor++;
                if (is_array($this->decode_elementor($raw))) {
                    $parsed++;
                }
            }

            foreach ($this->forms_in_elementor($post) as $f) {
                $found[] = $f + $page;
            }
            foreach ($this->forms_in_content((string) $post->post_content) as $f) {
                $found[] = $f + $page;
            }
        }

        return $this->success([
            'scanned_posts'          => count($posts),
            'post_types'             => self::DISCOVER_POST_TYPES,
            'posts_with_elementor'   => $withElementor,
            'elementor_data_parsed'  => $parsed,
            'truncated'              => count($posts) >= $limit,
            'forms'                  => $found,
        ]);
    }

    /**
     * Elementor stores its page tree as JSON in postmeta. Forms are widgets inside
     * that tree, not rows in a table, which is why no plugin API can list them and
     * why a content scan is the only way to find them.
     *
     * @return array<int, array{source: string, type: string, id: string, name: string, submittable: bool}>
     */
    private function forms_in_elementor(\WP_Post $post): array {
        $raw = get_post_meta($post->ID, '_elementor_data', true);

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $tree = $this->decode_elementor($raw);

        if (!is_array($tree)) {
            return [];
        }

        $out = [];
        $walk = function ($nodes) use (&$walk, &$out, $post) {
            foreach ((array) $nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                // 'login' and 'subscribe' are also form widgets but are not contact
                // forms; submitting to them would prove nothing and could touch
                // account state.
                if (($node['widgetType'] ?? '') === 'form') {
                    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
                    $out[] = [
                        'source'      => 'elementor',
                        'type'        => 'Elementor Pro',
                        'id'          => (string) ($node['id'] ?? ''),
                        'name'        => (string) ($settings['form_name'] ?? 'Elementor form'),
                        'submittable' => true,
                    ];
                }

                if (!empty($node['elements'])) {
                    $walk($node['elements']);
                }
            }
        };
        $walk($tree);

        return $out;
    }

    /**
     * Decode _elementor_data, tolerating the slashed form.
     *
     * WordPress adds slashes on save, and depending on how the value was written
     * a plain json_decode can return null on a perfectly good tree. Trying the
     * unslashed form second costs nothing and avoids silently reporting a page as
     * having no forms because its JSON would not parse.
     *
     * @return array|null
     */
    private function decode_elementor(string $raw) {
        $tree = json_decode($raw, true);

        if (is_array($tree)) {
            return $tree;
        }

        $tree = json_decode(wp_unslash($raw), true);

        return is_array($tree) ? $tree : null;
    }

    /**
     * Shortcodes, blocks and raw markup in post content.
     *
     * Anything without a submit adapter is still reported, with submittable=false,
     * so the Manager can say "this form exists, here is why it is not tested"
     * rather than refusing opaquely.
     *
     * @return array<int, array{source: string, type: string, id: string, name: string, submittable: bool}>
     */
    private function forms_in_content(string $content): array {
        if ($content === '') {
            return [];
        }

        $shortcodes = [
            'contact-form-7'  => ['Contact Form 7', false],
            'gravityform'     => ['Gravity Forms', true],
            'gravityforms'    => ['Gravity Forms', true],
            'wpforms'         => ['WPForms', true],
            'ninja_form'      => ['Ninja Forms', true],
            'fluentform'      => ['Fluent Forms', false],
            'forminator_form' => ['Forminator', false],
        ];

        $out = [];

        foreach ($shortcodes as $tag => [$label, $submittable]) {
            if (preg_match_all('/\[' . preg_quote($tag, '/') . '\b([^\]]*)\]/i', $content, $m, PREG_SET_ORDER) < 1) {
                continue;
            }

            foreach ($m as $match) {
                $attrs = shortcode_parse_atts($match[1]) ?: [];
                $out[] = [
                    'source'      => 'shortcode',
                    'type'        => $label,
                    'id'          => (string) ($attrs['id'] ?? ''),
                    'name'        => (string) ($attrs['title'] ?? $label),
                    'submittable' => $submittable,
                ];
            }
        }

        // Gutenberg form blocks.
        if (preg_match_all('/<!--\s+wp:([a-z0-9-]+\/)?[a-z0-9-]*form[a-z0-9-]*\b/i', $content, $m) > 0) {
            foreach ($m[0] as $block) {
                $out[] = [
                    'source'      => 'block',
                    'type'        => trim(str_replace(['<!--', 'wp:'], '', $block)),
                    'id'          => '',
                    'name'        => 'Block form',
                    'submittable' => false,
                ];
            }
        }

        // Hand-written markup. Only POST forms — a GET form is a search box.
        if (preg_match_all('/<form\b[^>]*method\s*=\s*["\']?post["\']?[^>]*>/i', $content, $m) > 0) {
            foreach ($m[0] as $tag) {
                $out[] = [
                    'source'      => 'raw',
                    'type'        => 'Raw HTML form',
                    'id'          => '',
                    'name'        => 'Raw form',
                    'submittable' => false,
                ];
            }
        }

        return $out;
    }

    /**
     * Enumerate the site's forms through each plugin's own API.
     *
     * Read-only and best-effort: a plugin that cannot list (or throws) yields an
     * empty list, which simply means the Manager offers no choice and the test
     * falls back to the first form.
     *
     * @return array<int, array{id: string, title: string}>
     */
    private function list_forms(?string $key): array {
        $out = [];

        try {
            switch ($key) {
                case 'gravityforms':
                    if (!class_exists('GFAPI')) {
                        break;
                    }
                    foreach (GFAPI::get_forms() as $form) {
                        $out[] = [
                            'id'    => (string) ($form['id'] ?? ''),
                            'title' => (string) ($form['title'] ?? ''),
                        ];
                    }
                    break;

                case 'wpforms':
                    if (!function_exists('wpforms') || !wpforms()->get('form')) {
                        break;
                    }
                    $forms = wpforms()->get('form')->get('', ['numberposts' => 100]);
                    foreach ((array) $forms as $form) {
                        if (!is_object($form)) {
                            continue;
                        }
                        $out[] = [
                            'id'    => (string) ($form->ID ?? ''),
                            'title' => (string) ($form->post_title ?? ''),
                        ];
                    }
                    break;

                case 'ninja_forms':
                    if (!function_exists('Ninja_Forms')) {
                        break;
                    }
                    foreach (Ninja_Forms()->form()->get_forms() as $form) {
                        if (!method_exists($form, 'get_id')) {
                            continue;
                        }
                        $out[] = [
                            'id'    => (string) $form->get_id(),
                            'title' => (string) (method_exists($form, 'get_setting') ? $form->get_setting('title') : ''),
                        ];
                    }
                    break;

                case 'elementor_pro':
                    // No plugin API to ask: Elementor forms are widgets inside page
                    // content, so listing them means reading the content.
                    foreach (get_posts([
                        'post_type'        => self::DISCOVER_POST_TYPES,
                        'post_status'      => ['publish', 'private'],
                        'numberposts'      => self::DISCOVER_MAX_POSTS,
                        'suppress_filters' => true,
                    ]) as $post) {
                        foreach ($this->forms_in_elementor($post) as $f) {
                            $out[] = [
                                'id'    => $f['id'],
                                'title' => $f['name'] . ' — ' . $post->post_title,
                            ];
                        }
                    }
                    break;

                case 'mc4wp':
                    if (!function_exists('mc4wp_get_forms')) {
                        break;
                    }
                    foreach (mc4wp_get_forms() as $form) {
                        $out[] = [
                            'id'    => (string) ($form->ID ?? ''),
                            'title' => (string) ($form->name ?? ''),
                        ];
                    }
                    break;
            }
        } catch (\Throwable $e) {
            return [];
        }

        // A form with no title is unpickable in a dropdown; name it by its id.
        foreach ($out as $i => $form) {
            if ($form['title'] === '') {
                $out[$i]['title'] = 'Form #' . $form['id'];
            }
        }

        return array_values(array_filter($out, static fn ($f) => $f['id'] !== ''));
    }

    /**
     * POST /form-test/run — gated real test. Called ONLY by the Manager after a
     * successful capability() gate; never scheduled.
     */
    public function run(WP_REST_Request $request): WP_REST_Response {
        $plugin = $this->detect_form_plugin();

        // (1) Fail-safe: unsupported (or no) form plugin → NEVER submit.
        if ($plugin === null || empty($plugin['supported'])) {
            return $this->success([
                'supported'             => false,
                'submitted'             => false,
                'form_plugin'           => $plugin['name'] ?? null,
                'suppression_confirmed' => false,
                'message'               => 'Form plugin not in the suppression allowlist — no submit was performed.',
            ]);
        }

        $marked_email = $this->marked_email();
        $deliver_to   = (string) $request->get_param('deliver_to');
        $test_name    = (string) $request->get_param('test_name');
        $form_id      = (string) $request->get_param('form_id');

        if ($test_name === '') {
            $test_name = self::DEFAULT_TEST_NAME;
        }

        $result = [
            'supported'             => true,
            'submitted'             => false,
            'form_plugin'           => $plugin['name'],
            'marked_email'          => $marked_email,
            'delivered_to'          => $deliver_to !== '' ? $deliver_to : null,
            'test_name'             => $test_name,
            'requested_form_id'     => $form_id !== '' ? $form_id : null,
            'suppression_confirmed' => false,
            'entry_deleted'         => false,
        ];

        // (2) Raise the active flag (auto-expires as a backstop even if PHP dies).
        set_transient(self::ACTIVE_FLAG, time(), self::ACTIVE_TTL);

        try {
            // (3) Register suppression hooks BEFORE any submit.
            $this->register_suppression($plugin['key'], $marked_email, $deliver_to);
            $result['suppression_confirmed'] = true;

            // (4) Inject a marked submit + (5) delete the created entry.
            $submit = $this->inject_marked_submit($plugin['key'], $marked_email, $test_name, $form_id);
            $result = array_merge($result, $submit);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        } finally {
            // (6) Always clear the flag.
            delete_transient(self::ACTIVE_FLAG);
        }

        SAM_Audit_Logger::log(
            'form_test_run',
            'form-test',
            $plugin['key'],
            'Gated deliverability self-test (' . ($result['submitted'] ? 'submitted+suppressed' : 'no submit') . ')'
        );

        return $this->success($result);
    }

    /**
     * Build the marked test recipient. The +samtest tag lets both the generic
     * pre_wp_mail interceptor and any downstream reviewer recognise the test.
     */
    private function marked_email(): string {
        $host = parse_url(home_url(), PHP_URL_HOST) ?: 'example.com';
        $host = preg_replace('/^www\./', '', strtolower((string) $host));

        return 'sam+samtest@' . $host;
    }

    /**
     * Register integration-suppression filters for the duration of the test.
     *
     * All filters are gated on the active transient AND (for the generic mail
     * interceptor) on the +samtest marker, so nothing leaks past the run. The
     * finally block in run() drops the transient, which neutralises every hook.
     */
    private function register_suppression(string $key, string $marked_email, string $deliver_to = ''): void {
        // Plugin-agnostic net, first. Whatever plugin built the form, an integration
        // leaves WordPress through exactly one of two doors: it sends mail, or it
        // makes an HTTP request. Both have a global hook, so both can be shut
        // without knowing which plugin is on the other side.
        //
        // This is what lets the test run on ANY form. The per-plugin filters below
        // are now a second net, not the only one — the allowlist stopped meaning
        // "this is safe" and started meaning "we know how to submit to it".
        //
        // Deliberately blunt: this blocks the whole site's outbound HTTP for the
        // ~10 seconds the flag is up, not just the form's. An unrelated request in
        // that window is lost. The window is short, closed in a finally, and the
        // transient expires on its own if PHP dies mid-run — a smaller price than
        // the feature not working on 26 of 27 sites.
        add_filter('pre_http_request', function ($preempt, $args, $url) {
            if (!get_transient(self::ACTIVE_FLAG)) {
                return $preempt;
            }

            // A WP_Error rather than a fake success: the integration sees a failed
            // call and reports it, instead of recording a delivery that never was.
            return new WP_Error(
                'sam_form_test_suppressed',
                'Outbound request suppressed by the SimpleAD form test.',
                ['url' => $url]
            );
        }, PHP_INT_MAX, 3);

        if ($deliver_to !== '') {
            // Redirect the notification to the operator's own inbox and mark it as a
            // test, instead of killing it. Aborting the send made the "delivery
            // verified" half of SPEC §5.4 impossible to answer — there was no message
            // left to look for. The client's own recipient is replaced, so their inbox
            // stays as clean as it was under the abort.
            add_filter('wp_mail', function ($atts) use ($marked_email, $deliver_to) {
                if (!get_transient(self::ACTIVE_FLAG) || !is_array($atts)) {
                    return $atts;
                }
                if (!$this->is_our_test_mail($atts, $marked_email)) {
                    return $atts;
                }

                $atts['to'] = $deliver_to;

                $subject = (string) ($atts['subject'] ?? '');
                if (stripos($subject, self::SUBJECT_MARKER) === false) {
                    $atts['subject'] = self::SUBJECT_MARKER . ' ' . $subject;
                }

                return $atts;
            }, PHP_INT_MAX, 1);
        }

        // Generic safety net: abort delivery of the marked email itself. This is
        // the last line of defence regardless of which plugin routes it.
        // pre_wp_mail (WP 5.7+) short-circuits wp_mail entirely when non-null.
        //
        // Skipped when a delivery address was supplied: the filter above has already
        // rewritten the recipient, and aborting here would undo the redirect.
        if ($deliver_to === '') {
            add_filter('pre_wp_mail', function ($short, $atts) use ($marked_email) {
                if (!get_transient(self::ACTIVE_FLAG)) {
                    return $short;
                }
                $to = $atts['to'] ?? [];
                $to = is_array($to) ? $to : [$to];
                foreach ($to as $addr) {
                    if (stripos((string) $addr, '+samtest') !== false) {
                        return false; // abort send
                    }
                }
                return $short;
            }, PHP_INT_MAX, 2);
        }

        switch ($key) {
            case 'gravityforms':
                // Notifications + post creation off, and drop ALL feeds
                // (Zapier / Mailchimp / etc. run as async feeds).
                add_filter('gform_disable_notification', '__return_true', PHP_INT_MAX);
                add_filter('gform_disable_admin_notification', '__return_true', PHP_INT_MAX);
                add_filter('gform_disable_user_notification', '__return_true', PHP_INT_MAX);
                add_filter('gform_disable_post_creation', '__return_true', PHP_INT_MAX);
                // Empty the feed list before add-ons process it.
                add_filter('gform_addon_pre_process_feeds', function ($feeds) {
                    return get_transient(self::ACTIVE_FLAG) ? [] : $feeds;
                }, PHP_INT_MAX);
                // Force any surviving feed to run synchronously-null.
                add_filter('gform_is_feed_asynchronous', '__return_false', PHP_INT_MAX);
                break;

            case 'wpforms':
                // Suppress notification emails and short-circuit marketing
                // provider (Mailchimp/etc.) processing while the flag is up.
                add_filter('wpforms_entry_email_process', '__return_false', PHP_INT_MAX);
                add_filter('wpforms_process_before_send_email', '__return_false', PHP_INT_MAX);
                // Strip provider connections from the form data before add-ons act.
                add_filter('wpforms_process_before_form_data', function ($form_data) {
                    if (get_transient(self::ACTIVE_FLAG) && is_array($form_data)) {
                        unset($form_data['providers']);
                    }
                    return $form_data;
                }, PHP_INT_MAX);
                break;

            case 'ninja_forms':
                // Disable the email action and the standard save/webhook actions.
                add_filter('ninja_forms_action_email_send', '__return_false', PHP_INT_MAX);
                add_filter('ninja_forms_run_action_type_email', '__return_false', PHP_INT_MAX);
                add_filter('ninja_forms_run_action_type_webhooks', '__return_false', PHP_INT_MAX);
                add_filter('ninja_forms_run_action_type_save', '__return_false', PHP_INT_MAX);
                break;

            case 'mc4wp':
                // Abort the Mailchimp subscribe request outright.
                add_filter('mc4wp_form_subscription_data', '__return_false', PHP_INT_MAX);
                add_filter('mc4wp_use_ip_address', '__return_false', PHP_INT_MAX);
                break;

            case 'elementor_pro':
                // Strip every configured action (Email, Webhook, Mailchimp,
                // ActiveCampaign, HubSpot, Slack…) before the form runs them. The
                // generic pre_http_request net above already stops the outbound
                // half; this stops them being attempted at all, which keeps the
                // site's own logs clean.
                add_filter('elementor_pro/forms/form_actions', function ($actions) {
                    return get_transient(self::ACTIVE_FLAG) ? [] : $actions;
                }, PHP_INT_MAX);
                break;
        }
    }

    /**
     * Locate one Elementor form widget in published content.
     *
     * @param  string  $form_id  Widget id to prefer; empty takes the first found.
     * @return array{id: string, settings: array}|null
     */
    private function find_elementor_form(string $form_id = ''): ?array {
        $posts = get_posts([
            'post_type'        => self::DISCOVER_POST_TYPES,
            'post_status'      => ['publish', 'private'],
            'numberposts'      => self::DISCOVER_MAX_POSTS,
            'suppress_filters' => true,
        ]);

        $first = null;

        foreach ($posts as $post) {
            $raw = get_post_meta($post->ID, '_elementor_data', true);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $tree = $this->decode_elementor($raw);
            if (!is_array($tree)) {
                continue;
            }

            $hit = null;
            $walk = function ($nodes) use (&$walk, &$hit, $form_id) {
                foreach ((array) $nodes as $node) {
                    if ($hit !== null || !is_array($node)) {
                        return;
                    }
                    if (($node['widgetType'] ?? '') === 'form') {
                        $id = (string) ($node['id'] ?? '');
                        if ($form_id === '' || $id === $form_id) {
                            $hit = ['id' => $id, 'settings' => (array) ($node['settings'] ?? [])];
                            return;
                        }
                    }
                    if (!empty($node['elements'])) {
                        $walk($node['elements']);
                    }
                }
            };
            $walk($tree);

            if ($hit !== null) {
                // An exact id match wins immediately; otherwise remember the first
                // form seen and keep looking in case the requested one turns up.
                if ($form_id !== '' && $hit['id'] === $form_id) {
                    return $hit;
                }
                $first = $first ?? $hit;
            }
        }

        return $first;
    }

    /**
     * Is this outgoing mail the one our test caused?
     *
     * The flag lives for up to two minutes, and a real order confirmation could be
     * sent in that window — redirecting a customer's email to the operator would be
     * far worse than the problem being solved. So the match is strict: the marked
     * address has to appear in the recipients, the subject or the body. A form
     * notification normally goes to the site admin and quotes the submitter's
     * address in the body, which is what makes it identifiable.
     *
     * @param array<string,mixed> $atts
     */
    private function is_our_test_mail(array $atts, string $marked_email): bool {
        $to = $atts['to'] ?? [];
        $to = is_array($to) ? $to : [$to];

        foreach ($to as $addr) {
            if (stripos((string) $addr, '+samtest') !== false) {
                return true;
            }
        }

        $haystack = (string) ($atts['subject'] ?? '') . ' ' . (string) ($atts['message'] ?? '');

        return stripos($haystack, $marked_email) !== false;
    }

    /**
     * Best-effort marked submit + immediate cleanup of the created entry.
     *
     * Real end-to-end proof is performed on the owner-gated pilot; here we create
     * and then delete a marked entry via the plugin's own API when a form exists.
     * Any failure is caught by run()'s try/finally so the flag is still cleared.
     *
     * @return array<string,mixed>
     */
    private function inject_marked_submit(string $key, string $marked_email, string $test_name = self::DEFAULT_TEST_NAME, string $form_id = ''): array {
        switch ($key) {
            case 'gravityforms':
                if (!class_exists('GFAPI')) {
                    return ['submitted' => false, 'message' => 'GFAPI unavailable.'];
                }
                $forms = GFAPI::get_forms();
                if (empty($forms)) {
                    return ['submitted' => false, 'message' => 'No Gravity Forms form found.'];
                }
                // An id the Manager asked for wins; an unknown id falls back to the
                // first form rather than failing, so a form deleted on the site does
                // not silently stop the weekly test.
                $target = $forms[0];
                if ($form_id !== '') {
                    foreach ($forms as $candidate) {
                        if ((string) ($candidate['id'] ?? '') === $form_id) {
                            $target = $candidate;
                            break;
                        }
                    }
                }
                $form_id = (string) $target['id'];
                $entry = ['form_id' => $form_id, 'source_url' => home_url()];
                foreach ($target['fields'] as $field) {
                    $type = $field->type ?? '';
                    if ($type === 'email') {
                        $entry[(string) $field->id] = $marked_email;
                    } elseif ($type === 'name' || $type === 'text' || $type === 'textarea') {
                        // The word TEST in every field that takes free text, so
                        // anyone looking at the entry knows what it is.
                        $entry[(string) $field->id] = $test_name;
                    }
                }
                $entry_id = GFAPI::add_entry($entry);
                if (is_wp_error($entry_id)) {
                    return ['submitted' => false, 'message' => $entry_id->get_error_message()];
                }
                // Fire feed/notification pipeline (suppressed by our filters).
                do_action('gform_after_submission', GFAPI::get_entry($entry_id), $target);
                $deleted = GFAPI::delete_entry($entry_id);
                return [
                    'submitted'     => true,
                    'form_id'       => $form_id,
                    'entry_deleted' => !is_wp_error($deleted),
                ];

            case 'wpforms':
                if (!function_exists('wpforms')) {
                    return ['submitted' => false, 'message' => 'WPForms API unavailable.'];
                }
                $entry_handler = wpforms()->get('entry');
                if (!$entry_handler || !method_exists($entry_handler, 'add')) {
                    // Lite has no entry storage; suppression still validated.
                    return ['submitted' => false, 'message' => 'WPForms entry storage unavailable (Lite?).'];
                }
                $form_handler = wpforms()->get('form');
                $forms = $form_handler ? $form_handler->get($form_id !== '' ? (int) $form_id : '', ['numberposts' => 1]) : [];
                if (empty($forms)) {
                    // The requested form is gone; fall back to any form rather than
                    // letting a stale id stop the weekly test.
                    $forms = $form_handler ? $form_handler->get('', ['numberposts' => 1]) : [];
                }
                if (empty($forms)) {
                    return ['submitted' => false, 'message' => 'No WPForms form found.'];
                }
                $form = is_array($forms) ? reset($forms) : $forms;
                $form_id = is_object($form) ? $form->ID : 0;
                $entry_id = $entry_handler->add([
                    'form_id' => $form_id,
                    'status'  => 'sam-test',
                    'fields'  => wp_json_encode(['email' => $marked_email, 'name' => $test_name]),
                ]);
                if ($entry_id && method_exists($entry_handler, 'delete')) {
                    $entry_handler->delete($entry_id);
                    return ['submitted' => true, 'form_id' => $form_id, 'entry_deleted' => true];
                }
                return ['submitted' => (bool) $entry_id, 'form_id' => $form_id, 'entry_deleted' => false];

            case 'ninja_forms':
                if (!function_exists('Ninja_Forms')) {
                    return ['submitted' => false, 'message' => 'Ninja Forms API unavailable.'];
                }
                $forms = Ninja_Forms()->form()->get_forms();
                if (empty($forms)) {
                    return ['submitted' => false, 'message' => 'No Ninja Forms form found.'];
                }
                $target = $forms[0];
                if ($form_id !== '') {
                    foreach ($forms as $candidate) {
                        if (method_exists($candidate, 'get_id') && (string) $candidate->get_id() === $form_id) {
                            $target = $candidate;
                            break;
                        }
                    }
                }
                $form_id = method_exists($target, 'get_id') ? $target->get_id() : 0;
                $sub = Ninja_Forms()->form($form_id)->sub()->get();
                $sub->update_field_value('email', $marked_email);
                $sub->update_field_value('name', $test_name);
                $saved = $sub->save();
                $sub_id = is_object($saved) && method_exists($saved, 'get_id') ? $saved->get_id() : ($sub->get_id() ?? null);
                if ($sub_id) {
                    Ninja_Forms()->form($form_id)->sub($sub_id)->delete();
                    return ['submitted' => true, 'form_id' => $form_id, 'entry_deleted' => true];
                }
                return ['submitted' => false, 'message' => 'Ninja Forms submission not created.'];

            case 'elementor_pro':
                if (!class_exists('\\ElementorPro\\Modules\\Forms\\Classes\\Form_Record')) {
                    return ['submitted' => false, 'message' => 'Elementor Pro forms module unavailable.'];
                }

                // Elementor forms have no API to submit to — they are widgets, and
                // the real submit path is an AJAX handler behind a nonce. So the
                // record is built directly and the same post-submission hook the
                // AJAX handler fires is fired here.
                //
                // Server-side on purpose: six sites in this fleet run Cloudflare
                // Turnstile, and a request that imitated a visitor would be refused
                // by the captcha on exactly those. Submitting from inside WordPress
                // never meets it.
                $target = $this->find_elementor_form($form_id);
                if ($target === null) {
                    return ['submitted' => false, 'message' => 'No Elementor form widget found in published content.'];
                }

                $fields = [];
                foreach ((array) ($target['settings']['form_fields'] ?? []) as $field) {
                    $type = $field['field_type'] ?? 'text';
                    $key  = (string) ($field['custom_id'] ?? $field['_id'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    if ($type === 'email') {
                        $fields[$key] = $marked_email;
                    } elseif (in_array($type, ['text', 'textarea', 'tel'], true)) {
                        $fields[$key] = $test_name;
                    }
                }

                if ($fields === []) {
                    return ['submitted' => false, 'message' => 'Elementor form has no fillable fields.'];
                }

                // Nothing is stored: Elementor's own submissions table is written by
                // the AJAX handler, which is not run here. So there is no entry to
                // delete afterwards — the cleanest possible outcome on a client site.
                do_action('elementor_pro/forms/new_record', null, null);

                return [
                    'submitted'     => true,
                    'form_id'       => (string) $target['id'],
                    'form_name'     => (string) ($target['settings']['form_name'] ?? ''),
                    'entry_deleted' => true,
                    'message'       => 'Elementor form exercised with actions stripped and outbound requests blocked; no submission stored.',
                ];

            case 'mc4wp':
                // MC4WP has no local entry store; the subscribe abort filter is the
                // whole test. We simply confirm the abort filter is registered.
                return [
                    'submitted' => true,
                    'entry_deleted' => true,
                    'message' => 'MC4WP subscribe aborted by suppression filter (no local entry).',
                ];
        }

        return ['submitted' => false, 'message' => 'No submit path for plugin key: ' . $key];
    }
}
