<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /content-freshness endpoint - Returns posts/pages with freshness metadata.
 */
class SAM_Content_Freshness_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/content-freshness', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_content_freshness'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function get_content_freshness(WP_REST_Request $request): WP_REST_Response {
        $per_page  = min((int) ($request->get_param('per_page') ?: 200), 500);
        $post_type = $request->get_param('post_type') ?: ['post', 'page'];

        if (is_string($post_type)) {
            $post_type = explode(',', $post_type);
        }

        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'orderby'        => 'modified',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ];

        $query = new WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            $word_count = str_word_count(wp_strip_all_tags($post->post_content));
            $author = get_userdata($post->post_author);

            $items[] = [
                'id'            => $post->ID,
                'title'         => $post->post_title,
                'type'          => $post->post_type,
                'status'        => $post->post_status,
                'url'           => get_permalink($post->ID),
                'word_count'    => $word_count,
                'published_at'  => $post->post_date,
                'modified_at'   => $post->post_modified,
                'author'        => $author ? $author->display_name : 'Unknown',
            ];
        }

        wp_reset_postdata();

        return $this->success([
            'items' => $items,
            'total' => count($items),
        ]);
    }
}
