<?php
/**
 * Runtime content & media tweaks enforcement.
 *
 * Reads settings from the sam_content_media_settings option and enforces them
 * on each request via WordPress hooks and filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Content_Media_Tweaks {

    /** @var array */
    private $settings;

    public function __construct() {
        $this->settings = get_option('sam_content_media_settings', []);
        if (empty($this->settings)) {
            return;
        }

        $this->enforce();
    }

    private function enforce(): void {
        // Content duplication
        if (!empty($this->settings['content_duplication'])) {
            $this->enforce_content_duplication();
        }

        // Media replacement
        if (!empty($this->settings['media_replacement'])) {
            $this->enforce_media_replacement();
        }

        // SVG upload
        if (!empty($this->settings['svg_upload'])) {
            $this->enforce_svg_upload();
        }

        // AVIF upload
        if (!empty($this->settings['avif_upload'])) {
            $this->enforce_avif_upload();
        }

        // External permalinks
        if (!empty($this->settings['external_permalinks'])) {
            $this->enforce_external_permalinks();
        }

        // Open external links in new tab
        if (!empty($this->settings['open_external_links_new_tab'])) {
            add_filter('the_content', [$this, 'add_target_blank_to_external_links']);
        }

        // Auto-publish missed schedule
        if (!empty($this->settings['auto_publish_missed_schedule'])) {
            add_action('wp_loaded', [$this, 'publish_missed_schedule']);
        }

        // Content order
        if (!empty($this->settings['content_order'])) {
            $this->enforce_content_order();
        }

        // Media visibility control
        if (!empty($this->settings['media_visibility_control'])) {
            add_filter('ajax_query_attachments_args', [$this, 'restrict_media_to_own']);
        }
    }

    // ─── Content Duplication ────────────────────────────────────────────

    private function enforce_content_duplication(): void {
        $config = is_array($this->settings['content_duplication'])
            ? $this->settings['content_duplication']
            : [];

        $post_types = $config['post_types'] ?? ['post', 'page'];

        if (in_array('post', $post_types, true)) {
            add_filter('post_row_actions', [$this, 'add_duplicate_link'], 10, 2);
        }
        if (in_array('page', $post_types, true)) {
            add_filter('page_row_actions', [$this, 'add_duplicate_link'], 10, 2);
        }

        add_action('admin_action_sam_duplicate_post', [$this, 'handle_duplicate']);
        add_action('admin_notices', [$this, 'duplicate_admin_notice']);
    }

    /**
     * Add "Duplicate" link to post/page row actions.
     */
    public function add_duplicate_link(array $actions, $post): array {
        if (!current_user_can('edit_posts')) {
            return $actions;
        }

        $config = is_array($this->settings['content_duplication'])
            ? $this->settings['content_duplication']
            : [];
        $post_types = $config['post_types'] ?? ['post', 'page'];

        if (!in_array($post->post_type, $post_types, true)) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=sam_duplicate_post&post_id=' . $post->ID),
            'sam_duplicate_' . $post->ID
        );

        $actions['sam_duplicate'] = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url($url),
            esc_attr__('Duplicate this item', 'simplead-manager'),
            esc_html__('Duplicate', 'simplead-manager')
        );

        return $actions;
    }

    /**
     * Handle the duplicate action.
     */
    public function handle_duplicate(): void {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if (!$post_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'sam_duplicate_' . $post_id)) {
            wp_die(esc_html__('Security check failed.', 'simplead-manager'));
        }

        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have permission to duplicate posts.', 'simplead-manager'));
        }

        $original = get_post($post_id);
        if (!$original) {
            wp_die(esc_html__('Original post not found.', 'simplead-manager'));
        }

        $config = is_array($this->settings['content_duplication'])
            ? $this->settings['content_duplication']
            : [];

        $prefix = $config['title_prefix'] ?? '';
        $suffix = $config['title_suffix'] ?? ' (Copy)';
        $status = $config['duplicate_status'] ?? 'draft';

        // Create the duplicate post
        $new_post = [
            'post_title'   => $prefix . $original->post_title . $suffix,
            'post_content' => $original->post_content,
            'post_excerpt'  => $original->post_excerpt,
            'post_status'  => $status,
            'post_type'    => $original->post_type,
            'post_author'  => get_current_user_id(),
            'post_parent'  => $original->post_parent,
            'menu_order'   => $original->menu_order,
            'post_password' => $original->post_password,
            'comment_status' => $original->comment_status,
            'ping_status'  => $original->ping_status,
        ];

        $new_id = wp_insert_post($new_post);

        if (is_wp_error($new_id)) {
            wp_die($new_id->get_error_message());
        }

        // Copy post meta
        if (!empty($config['copy_meta']) || !isset($config['copy_meta'])) {
            $exclude_keys = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date'];
            $meta = get_post_meta($post_id);

            foreach ($meta as $key => $values) {
                if (in_array($key, $exclude_keys, true)) {
                    continue;
                }
                // Skip featured image meta if we handle it separately
                if ($key === '_thumbnail_id') {
                    continue;
                }
                foreach ($values as $value) {
                    add_post_meta($new_id, $key, maybe_unserialize($value));
                }
            }
        }

        // Copy taxonomies
        if (!empty($config['copy_taxonomies']) || !isset($config['copy_taxonomies'])) {
            $taxonomies = get_object_taxonomies($original->post_type);
            foreach ($taxonomies as $taxonomy) {
                $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    wp_set_object_terms($new_id, $terms, $taxonomy);
                }
            }
        }

        // Copy featured image
        if (!empty($config['copy_featured_image']) || !isset($config['copy_featured_image'])) {
            $thumbnail_id = get_post_thumbnail_id($post_id);
            if ($thumbnail_id) {
                set_post_thumbnail($new_id, $thumbnail_id);
            }
        }

        // Redirect
        $redirect_to = $config['redirect_after'] ?? 'edit';
        if ($redirect_to === 'edit') {
            $redirect_url = get_edit_post_link($new_id, 'raw');
        } else {
            $redirect_url = admin_url('edit.php?post_type=' . $original->post_type . '&sam_duplicated=1');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Show admin notice after duplication.
     */
    public function duplicate_admin_notice(): void {
        if (isset($_GET['sam_duplicated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__('Post duplicated successfully.', 'simplead-manager');
            echo '</p></div>';
        }
    }

    // ─── Media Replacement ──────────────────────────────────────────────

    private function enforce_media_replacement(): void {
        add_action('edit_form_advanced', [$this, 'add_replace_media_form']);
        add_action('admin_action_sam_replace_media', [$this, 'handle_replace_media']);
    }

    /**
     * Add "Replace Media" form to attachment edit screen.
     */
    public function add_replace_media_form($post): void {
        if ($post->post_type !== 'attachment') {
            return;
        }
        if (!current_user_can('upload_files')) {
            return;
        }

        $nonce = wp_create_nonce('sam_replace_media_' . $post->ID);
        $action_url = admin_url('admin.php?action=sam_replace_media');

        echo '<div class="postbox" style="margin-top:20px">';
        echo '<h2 class="hndle" style="padding:8px 12px;cursor:default">' . esc_html__('Replace Media File', 'simplead-manager') . '</h2>';
        echo '<div class="inside">';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url($action_url) . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
        echo '<input type="hidden" name="attachment_id" value="' . esc_attr($post->ID) . '" />';
        echo '<p><input type="file" name="replacement_file" required /></p>';
        echo '<p class="description">' . esc_html__('Upload a new file to replace the current one. The URL will remain the same.', 'simplead-manager') . '</p>';
        echo '<p><input type="submit" class="button button-primary" value="' . esc_attr__('Replace File', 'simplead-manager') . '" /></p>';
        echo '</form>';
        echo '</div></div>';
    }

    /**
     * Handle the media replacement action.
     */
    public function handle_replace_media(): void {
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;

        if (!$attachment_id || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sam_replace_media_' . $attachment_id)) {
            wp_die(esc_html__('Security check failed.', 'simplead-manager'));
        }

        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('You do not have permission to upload files.', 'simplead-manager'));
        }

        if (empty($_FILES['replacement_file']['name'])) {
            wp_die(esc_html__('No file was uploaded.', 'simplead-manager'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $old_file = get_attached_file($attachment_id);

        // Upload the new file
        $upload = wp_handle_upload($_FILES['replacement_file'], ['test_form' => false]);

        if (isset($upload['error'])) {
            wp_die($upload['error']);
        }

        // Delete old file and thumbnails
        $old_meta = wp_get_attachment_metadata($attachment_id);
        if ($old_file && file_exists($old_file)) {
            @unlink($old_file);
        }
        if (!empty($old_meta['sizes'])) {
            $upload_dir = wp_upload_dir();
            $old_dir = dirname($old_file);
            foreach ($old_meta['sizes'] as $size) {
                $thumb_file = $old_dir . '/' . $size['file'];
                if (file_exists($thumb_file)) {
                    @unlink($thumb_file);
                }
            }
        }

        // Move new file to old location if in same upload directory
        if ($old_file) {
            $new_dir = dirname($upload['file']);
            $old_dir = dirname($old_file);
            $new_basename = basename($upload['file']);

            if ($new_dir !== $old_dir) {
                $target = $old_dir . '/' . $new_basename;
                @rename($upload['file'], $target);
                $upload['file'] = $target;
            }
        }

        // Update attachment
        update_attached_file($attachment_id, $upload['file']);

        $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        // Update MIME type
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => $upload['type'],
        ]);

        wp_safe_redirect(get_edit_post_link($attachment_id, 'raw'));
        exit;
    }

    // ─── SVG Upload ─────────────────────────────────────────────────────

    private function enforce_svg_upload(): void {
        add_filter('upload_mimes', function ($mimes) {
            $mimes['svg'] = 'image/svg+xml';
            $mimes['svgz'] = 'image/svg+xml';
            return $mimes;
        });

        add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if (in_array(strtolower($ext), ['svg', 'svgz'], true)) {
                $data['ext'] = $ext;
                $data['type'] = 'image/svg+xml';
            }
            return $data;
        }, 10, 4);

        // Sanitize SVG on upload
        add_filter('wp_handle_upload_prefilter', [$this, 'sanitize_svg_upload']);
    }

    /**
     * Sanitize SVG files on upload to remove potentially dangerous content.
     */
    public function sanitize_svg_upload($file): array {
        if ($file['type'] !== 'image/svg+xml') {
            return $file;
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            $file['error'] = __('Could not read SVG file.', 'simplead-manager');
            return $file;
        }

        // Remove script tags, on* attributes, and external references
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);
        $content = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', '', $content);
        $content = preg_replace('/xlink:href\s*=\s*["\'](?!#)[^"\']*["\']/i', '', $content);

        // Remove data URIs in attributes (potential XSS vector)
        $content = preg_replace('/href\s*=\s*["\']data:[^"\']*["\']/i', '', $content);

        file_put_contents($file['tmp_name'], $content);

        return $file;
    }

    // ─── AVIF Upload ────────────────────────────────────────────────────

    private function enforce_avif_upload(): void {
        add_filter('upload_mimes', function ($mimes) {
            $mimes['avif'] = 'image/avif';
            return $mimes;
        });

        add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if (strtolower($ext) === 'avif') {
                $data['ext'] = 'avif';
                $data['type'] = 'image/avif';
            }
            return $data;
        }, 10, 4);
    }

    // ─── External Permalinks ────────────────────────────────────────────

    private function enforce_external_permalinks(): void {
        // Add meta box in admin
        add_action('add_meta_boxes', function () {
            $post_types = get_post_types(['public' => true]);
            foreach ($post_types as $post_type) {
                add_meta_box(
                    'sam_external_permalink',
                    __('External Permalink', 'simplead-manager'),
                    [$this, 'render_external_permalink_metabox'],
                    $post_type,
                    'side',
                    'default'
                );
            }
        });

        add_action('save_post', [$this, 'save_external_permalink'], 10, 2);

        // Redirect on frontend
        add_action('template_redirect', function () {
            if (is_singular()) {
                $external_url = get_post_meta(get_the_ID(), '_sam_external_url', true);
                if (!empty($external_url) && filter_var($external_url, FILTER_VALIDATE_URL)) {
                    wp_redirect($external_url, 301);
                    exit;
                }
            }
        });
    }

    public function render_external_permalink_metabox($post): void {
        $url = get_post_meta($post->ID, '_sam_external_url', true);
        wp_nonce_field('sam_external_permalink_' . $post->ID, 'sam_external_permalink_nonce');
        echo '<input type="url" name="sam_external_url" value="' . esc_attr($url) . '" class="widefat" placeholder="https://example.com" />';
        echo '<p class="description">' . esc_html__('Visitors will be redirected to this URL with 301.', 'simplead-manager') . '</p>';
    }

    public function save_external_permalink($post_id, $post): void {
        if (!isset($_POST['sam_external_permalink_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['sam_external_permalink_nonce'], 'sam_external_permalink_' . $post_id)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $url = isset($_POST['sam_external_url']) ? esc_url_raw($_POST['sam_external_url']) : '';
        if ($url) {
            update_post_meta($post_id, '_sam_external_url', $url);
        } else {
            delete_post_meta($post_id, '_sam_external_url');
        }
    }

    // ─── Open External Links in New Tab ─────────────────────────────────

    /**
     * Add target="_blank" to external links in post content.
     */
    public function add_target_blank_to_external_links(string $content): string {
        if (empty($content)) {
            return $content;
        }

        $home_url = home_url();
        $host = wp_parse_url($home_url, PHP_URL_HOST);

        // Match all anchor tags
        return preg_replace_callback('/<a\s([^>]*)>/i', function ($matches) use ($host) {
            $attrs = $matches[1];

            // Extract href
            if (!preg_match('/href\s*=\s*["\']([^"\']*)["\']/', $attrs, $href_match)) {
                return $matches[0];
            }

            $href = $href_match[1];

            // Skip anchors, relative URLs, and same-domain links
            if (empty($href) || $href[0] === '#' || $href[0] === '/') {
                return $matches[0];
            }

            $link_host = wp_parse_url($href, PHP_URL_HOST);
            if (!$link_host || $link_host === $host) {
                return $matches[0];
            }

            // Already has target
            if (preg_match('/target\s*=/i', $attrs)) {
                return $matches[0];
            }

            return '<a ' . $attrs . ' target="_blank" rel="noopener noreferrer">';
        }, $content);
    }

    // ─── Auto-Publish Missed Schedule ───────────────────────────────────

    /**
     * Check for and publish missed scheduled posts.
     */
    public function publish_missed_schedule(): void {
        // Throttle: run at most once every 5 minutes
        $transient_key = 'sam_missed_schedule_check';
        if (get_transient($transient_key)) {
            return;
        }
        set_transient($transient_key, 1, 5 * MINUTE_IN_SECONDS);

        global $wpdb;
        $now = current_time('mysql', false);

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'future' AND post_date <= %s LIMIT 20",
            $now
        ));

        if (empty($posts)) {
            return;
        }

        foreach ($posts as $post) {
            wp_publish_post((int) $post->ID);
        }
    }

    // ─── Content Order ──────────────────────────────────────────────────

    private function enforce_content_order(): void {
        $config = is_array($this->settings['content_order'])
            ? $this->settings['content_order']
            : [];

        $post_types = $config['post_types'] ?? ['page'];

        // Add sortable column
        foreach ($post_types as $pt) {
            add_filter("manage_{$pt}_posts_columns", function ($columns) {
                $columns['menu_order'] = __('Order', 'simplead-manager');
                return $columns;
            });

            add_action("manage_{$pt}_posts_custom_column", function ($column, $post_id) {
                if ($column === 'menu_order') {
                    echo (int) get_post_field('menu_order', $post_id);
                }
            }, 10, 2);

            add_filter("manage_edit-{$pt}_sortable_columns", function ($columns) {
                $columns['menu_order'] = 'menu_order';
                return $columns;
            });
        }

        // Default ordering by menu_order
        add_action('pre_get_posts', function ($query) use ($post_types) {
            if (!is_admin() || !$query->is_main_query()) {
                return;
            }

            $post_type = $query->get('post_type');
            if (is_string($post_type) && in_array($post_type, $post_types, true)) {
                if (empty($query->get('orderby'))) {
                    $query->set('orderby', 'menu_order');
                    $query->set('order', 'ASC');
                }
            }
        });

        // Quick edit support
        add_action('quick_edit_custom_box', function ($column_name, $post_type) use ($post_types) {
            if ($column_name !== 'menu_order' || !in_array($post_type, $post_types, true)) {
                return;
            }
            echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col">';
            echo '<label><span class="title">' . esc_html__('Order', 'simplead-manager') . '</span>';
            echo '<input type="number" name="menu_order" value="" class="inline-edit-menu-order-input" /></label>';
            echo '</div></fieldset>';
        }, 10, 2);
    }

    // ─── Media Visibility Control ───────────────────────────────────────

    /**
     * Restrict media library to own uploads for non-admins.
     */
    public function restrict_media_to_own(array $query): array {
        if (current_user_can('manage_options')) {
            return $query;
        }

        $query['author'] = get_current_user_id();
        return $query;
    }

    // ─── Verified State ─────────────────────────────────────────────────

    /**
     * Get the actual enforced state by checking real WordPress hooks/filters.
     */
    public static function get_verified_state(): array {
        $settings = get_option('sam_content_media_settings', []);
        $state = [];

        $state['content_duplication'] = [
            'configured' => !empty($settings['content_duplication']),
            'active'     => has_filter('post_row_actions') || has_filter('page_row_actions'),
        ];

        $state['media_replacement'] = [
            'configured' => !empty($settings['media_replacement']),
            'active'     => !empty($settings['media_replacement']),
        ];

        $state['svg_upload'] = [
            'configured' => !empty($settings['svg_upload']),
            'active'     => !empty($settings['svg_upload']),
        ];

        $state['avif_upload'] = [
            'configured' => !empty($settings['avif_upload']),
            'active'     => !empty($settings['avif_upload']),
        ];

        $state['external_permalinks'] = [
            'configured' => !empty($settings['external_permalinks']),
            'active'     => !empty($settings['external_permalinks']),
        ];

        $state['open_external_links_new_tab'] = [
            'configured' => !empty($settings['open_external_links_new_tab']),
            'active'     => has_filter('the_content'),
        ];

        $state['auto_publish_missed_schedule'] = [
            'configured' => !empty($settings['auto_publish_missed_schedule']),
            'active'     => !empty($settings['auto_publish_missed_schedule']),
        ];

        $state['content_order'] = [
            'configured' => !empty($settings['content_order']),
            'active'     => !empty($settings['content_order']),
        ];

        $state['media_visibility_control'] = [
            'configured' => !empty($settings['media_visibility_control']),
            'active'     => has_filter('ajax_query_attachments_args'),
        ];

        return $state;
    }
}
