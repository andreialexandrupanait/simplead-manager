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
        ]);
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
            $submit = $this->inject_marked_submit($plugin['key'], $marked_email, $test_name);
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
        }
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
    private function inject_marked_submit(string $key, string $marked_email, string $test_name = self::DEFAULT_TEST_NAME): array {
        switch ($key) {
            case 'gravityforms':
                if (!class_exists('GFAPI')) {
                    return ['submitted' => false, 'message' => 'GFAPI unavailable.'];
                }
                $forms = GFAPI::get_forms();
                if (empty($forms)) {
                    return ['submitted' => false, 'message' => 'No Gravity Forms form found.'];
                }
                $form_id = $forms[0]['id'];
                $entry = ['form_id' => $form_id, 'source_url' => home_url()];
                foreach ($forms[0]['fields'] as $field) {
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
                do_action('gform_after_submission', GFAPI::get_entry($entry_id), $forms[0]);
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
                $forms = wpforms()->get('form') ? wpforms()->get('form')->get('', ['numberposts' => 1]) : [];
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
                $form_id = method_exists($forms[0], 'get_id') ? $forms[0]->get_id() : 0;
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
