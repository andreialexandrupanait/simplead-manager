<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /posts endpoint - Creates posts and retrieves categories/tags.
 */
class SAM_Posts_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/posts', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_post'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/posts/categories', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_categories'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/posts/tags', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_tags'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Create a new post.
     */
    public function create_post(WP_REST_Request $request): WP_REST_Response {
        $title   = sanitize_text_field($request->get_param('title') ?? '');
        $content = wp_kses_post($request->get_param('content') ?? '');
        $status  = sanitize_text_field($request->get_param('status') ?? 'draft');
        $slug    = sanitize_title($request->get_param('slug') ?? '');

        if (empty($title)) {
            return $this->error('missing_title', 'Post title is required.', 400);
        }

        // Validate status
        $allowed_statuses = ['draft', 'pending', 'publish', 'future'];
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'draft';
        }

        $post_data = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_type'    => 'post',
        ];

        if ($slug) {
            $post_data['post_name'] = $slug;
        }

        // Optional: category IDs
        $category_ids = $request->get_param('category_ids');
        if (is_array($category_ids)) {
            $post_data['post_category'] = array_map('intval', $category_ids);
        }

        // Optional: author
        $author_id = $request->get_param('author_id');
        if ($author_id) {
            $post_data['post_author'] = (int) $author_id;
        }

        // Optional: scheduled date
        $post_date = $request->get_param('post_date');
        if ($post_date && $status === 'future') {
            $post_data['post_date']     = $post_date;
            $post_data['post_date_gmt'] = get_gmt_from_date($post_date);
        }

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return $this->error('insert_failed', $post_id->get_error_message(), 500);
        }

        // Optional: tags
        $tag_ids = $request->get_param('tag_ids');
        if (is_array($tag_ids)) {
            wp_set_post_tags($post_id, array_map('intval', $tag_ids));
        }

        $tag_names = $request->get_param('tags');
        if (is_array($tag_names)) {
            wp_set_post_tags($post_id, array_map('sanitize_text_field', $tag_names), true);
        }

        // Optional: meta description via Yoast or RankMath
        $meta_description = sanitize_text_field($request->get_param('meta_description') ?? '');
        $meta_title       = sanitize_text_field($request->get_param('meta_title') ?? '');

        if ($meta_description || $meta_title) {
            $this->set_seo_meta($post_id, $meta_title, $meta_description);
        }

        // Optional: featured image from URL
        $featured_image_url = esc_url_raw($request->get_param('featured_image_url') ?? '');
        if ($featured_image_url) {
            $this->set_featured_image_from_url($post_id, $featured_image_url);
        }

        return $this->success([
            'post_id'   => $post_id,
            'permalink' => get_permalink($post_id),
            'edit_url'  => get_edit_post_link($post_id, 'raw'),
            'status'    => get_post_status($post_id),
        ]);
    }

    /**
     * List available categories.
     */
    public function get_categories(WP_REST_Request $request): WP_REST_Response {
        $categories = get_categories([
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        $result = [];
        foreach ($categories as $cat) {
            $result[] = [
                'id'     => $cat->term_id,
                'name'   => $cat->name,
                'slug'   => $cat->slug,
                'parent' => $cat->parent,
                'count'  => $cat->count,
            ];
        }

        return $this->success(['categories' => $result]);
    }

    /**
     * List available tags.
     */
    public function get_tags(WP_REST_Request $request): WP_REST_Response {
        $tags = get_tags([
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        $result = [];
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                $result[] = [
                    'id'    => $tag->term_id,
                    'name'  => $tag->name,
                    'slug'  => $tag->slug,
                    'count' => $tag->count,
                ];
            }
        }

        return $this->success(['tags' => $result]);
    }

    /**
     * Set SEO meta via Yoast or RankMath if available.
     */
    private function set_seo_meta(int $post_id, string $title, string $description): void {
        // Yoast SEO
        if (defined('WPSEO_VERSION')) {
            if ($title) {
                update_post_meta($post_id, '_yoast_wpseo_title', $title);
            }
            if ($description) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $description);
            }
            return;
        }

        // RankMath
        if (defined('RANK_MATH_VERSION')) {
            if ($title) {
                update_post_meta($post_id, 'rank_math_title', $title);
            }
            if ($description) {
                update_post_meta($post_id, 'rank_math_description', $description);
            }
            return;
        }

        // Fallback: standard meta
        if ($description) {
            update_post_meta($post_id, '_sam_meta_description', $description);
        }
    }

    /**
     * Download and set featured image from URL.
     */
    private function set_featured_image_from_url(int $post_id, string $url): void {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_sideload_image($url, $post_id, null, 'id');

        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
}
