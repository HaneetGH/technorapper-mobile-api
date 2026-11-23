<?php
if (!defined('ABSPATH')) exit;

final class TR_Mobile_API {
    private const MAX_LIMIT = 20;
    private const MAX_PAGE = 50;
    private const MAX_SEARCH_PAGE = 10;

    public function register_routes(): void {
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/auth/token', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'auth_token'],
            'permission_callback' => [$this, 'permission_issue_token'],
        ]);

        $read = [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => [$this, 'permission'],
        ];

        register_rest_route(TR_MOBILE_API_NAMESPACE, '/config', $read + [
            'callback' => [$this, 'config'],
        ]);
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/desks', $read + [
            'callback' => [$this, 'desks'],
        ]);
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/home', $read + [
            'callback' => [$this, 'home'],
            'args' => [
                'limit' => ['default' => 20, 'sanitize_callback' => 'absint'],
            ],
        ]);
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/posts', $read + [
            'callback' => [$this, 'posts'],
            'args' => [
                'page' => ['default' => 1, 'sanitize_callback' => 'absint'],
                'limit' => ['default' => 20, 'sanitize_callback' => 'absint'],
                'desk' => ['default' => '', 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/posts/(?P<id>\\d+)', $read + [
            'callback' => [$this, 'post'],
        ]);
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/search', $read + [
            'callback' => [$this, 'search'],
            'args' => [
                'q' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'page' => ['default' => 1, 'sanitize_callback' => 'absint'],
                'limit' => ['default' => 20, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    public function permission_issue_token(WP_REST_Request $request) {
        $rate = TR_Mobile_Rate_Limit::check($request);
        if (is_wp_error($rate)) {
            return $rate;
        }
        return true;
    }

    public function permission(WP_REST_Request $request) {
        $auth = TR_Mobile_Auth::authenticate($request);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $rate = TR_Mobile_Rate_Limit::check($request);
        if (is_wp_error($rate)) {
            return $rate;
        }
        return true;
    }

    public function auth_token(WP_REST_Request $request) {
        $issued = TR_Mobile_Auth::issue_token($request);
        if (is_wp_error($issued)) {
            return $issued;
        }
        $response = new WP_REST_Response($issued, 200);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function config(): WP_REST_Response {
        return new WP_REST_Response([
            'api_version' => 'v1',
            'plugin_version' => TR_MOBILE_API_VERSION,
            'min_app_version' => get_option('tr_mobile_min_app_version', '1.0.0'),
            'desks' => $this->desk_payload(),
            'features' => [
                'push_notifications' => false,
                'search' => true,
            ],
            'auth' => [
                'token_ttl' => TR_Mobile_Auth::token_ttl(),
                'token_path' => '/' . TR_MOBILE_API_NAMESPACE . '/auth/token',
            ],
        ], 200);
    }

    public function desks(): WP_REST_Response {
        return new WP_REST_Response(['data' => $this->desk_payload()], 200);
    }

    public function home(WP_REST_Request $request): WP_REST_Response {
        $limit = $this->clamp_limit((int) $request->get_param('limit'));
        $posts = $this->query_posts(1, $limit);
        return new WP_REST_Response([
            'data' => [
                'hero' => array_slice($posts['data'], 0, 1),
                'latest' => $posts['data'],
                'desks' => $this->desk_payload(),
            ],
            'meta' => ['generated_at' => gmdate('c')],
        ], 200);
    }

    public function posts(WP_REST_Request $request) {
        $page = $this->clamp_page((int) $request->get_param('page'), self::MAX_PAGE);
        if (is_wp_error($page)) {
            return $page;
        }
        $limit = $this->clamp_limit((int) $request->get_param('limit'));
        $desk = (string) $request->get_param('desk');
        $result = $this->query_posts($page, $limit, $desk);
        return new WP_REST_Response([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ], 200, $this->pagination_headers($result['meta']));
    }

    public function post(WP_REST_Request $request) {
        $post = get_post((int) $request['id']);
        if (
            !$post
            || $post->post_status !== 'publish'
            || $post->post_type !== 'post'
            || $post->post_password !== ''
        ) {
            return new WP_Error('tr_not_found', 'Post not found.', ['status' => 404]);
        }
        return new WP_REST_Response(['data' => $this->serialize_post($post, true)], 200);
    }

    public function search(WP_REST_Request $request) {
        $q = trim((string) $request->get_param('q'));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            return new WP_Error('tr_invalid_query', 'Search query must be between 2 and 100 characters.', ['status' => 400]);
        }
        $page = $this->clamp_page((int) $request->get_param('page'), self::MAX_SEARCH_PAGE);
        if (is_wp_error($page)) {
            return $page;
        }
        $limit = $this->clamp_limit((int) $request->get_param('limit'));
        $query = new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            's' => $q,
            'paged' => $page,
            'posts_per_page' => $limit,
            'ignore_sticky_posts' => true,
            'has_password' => false,
        ]);
        $items = array_map(fn($p) => $this->serialize_post($p), $query->posts);
        $meta = [
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
        ];
        return new WP_REST_Response([
            'data' => $items,
            'meta' => $meta,
        ], 200, $this->pagination_headers($meta));
    }

    private function query_posts(int $page, int $limit, string $desk = ''): array {
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'paged' => $page,
            'posts_per_page' => $limit,
            'ignore_sticky_posts' => true,
            'has_password' => false,
        ];
        if ($desk !== '') {
            $args['category_name'] = $desk;
        }
        $query = new WP_Query($args);
        $items = array_map(fn($p) => $this->serialize_post($p), $query->posts);
        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int) $query->found_posts,
                'pages' => (int) $query->max_num_pages,
            ],
        ];
    }

    private function serialize_post(WP_Post $post, bool $full = false): array {
        $categories = get_the_category($post->ID);
        $image = get_the_post_thumbnail_url($post->ID, 'technorapper-card')
            ?: get_the_post_thumbnail_url($post->ID, 'medium_large')
            ?: get_the_post_thumbnail_url($post->ID, 'full');
        $item = [
            'id' => (int) $post->ID,
            'slug' => $post->post_name,
            'title' => get_the_title($post),
            'excerpt' => wp_strip_all_tags(get_the_excerpt($post)),
            'date' => get_post_time('c', true, $post),
            'url' => get_permalink($post),
            'canonical_url' => get_permalink($post),
            'image' => $image ? esc_url_raw($image) : null,
            'desk' => !empty($categories)
                ? [
                    'id' => (int) $categories[0]->term_id,
                    'slug' => $categories[0]->slug,
                    'name' => $categories[0]->name,
                ]
                : null,
        ];
        if ($full) {
            // Avoid the_content shortcodes/embeds (CPU / outbound HTTP). App renders HTML safely.
            $item['content'] = wp_kses_post($post->post_content);
            $item['ai_wire'] = $this->ai_wire_disclosure($post->ID);
            $item['related'] = $this->related_posts($post->ID, $categories[0]->term_id ?? 0);
        }
        return $item;
    }

    private function related_posts(int $post_id, int $category_id): array {
        if (!$category_id) {
            return [];
        }
        $q = new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'cat' => $category_id,
            'post__not_in' => [$post_id],
            'ignore_sticky_posts' => true,
            'has_password' => false,
            'no_found_rows' => true,
        ]);
        return array_map(fn($p) => $this->serialize_post($p), $q->posts);
    }

    private function ai_wire_disclosure(int $post_id): ?array {
        $value = get_post_meta($post_id, '_tr_ai_wire_provenance', true);
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        return is_array($value) ? $value : null;
    }

    private function desk_payload(): array {
        $slugs = get_theme_mod('desk_slugs', '');
        $slugs = array_values(array_filter(array_map('sanitize_title', explode(',', (string) $slugs))));
        if (!$slugs) {
            $defaults = ['apple', 'ai', 'android', 'auto', 'pc-and-hardware', 'reviews', 'comics', 'guides', 'news'];
            $slugs = array_values(array_filter($defaults, fn($slug) => term_exists($slug, 'category')));
        }
        $items = [];
        foreach ($slugs as $order => $slug) {
            $term = get_term_by('slug', $slug, 'category');
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $items[] = [
                'id' => (int) $term->term_id,
                'slug' => $term->slug,
                'name' => $term->name,
                'order' => $order,
            ];
        }
        return $items;
    }

    private function clamp_limit(int $limit): int {
        if ($limit < 1) {
            $limit = self::MAX_LIMIT;
        }
        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @return int|WP_Error
     */
    private function clamp_page(int $page, int $max) {
        $page = max(1, $page);
        if ($page > $max) {
            return new WP_Error(
                'tr_invalid_page',
                sprintf('Page must be between 1 and %d.', $max),
                ['status' => 400]
            );
        }
        return $page;
    }

    private function pagination_headers(array $meta): array {
        return [
            'X-TR-Page' => (string) ($meta['page'] ?? 1),
            'X-TR-Pages' => (string) ($meta['pages'] ?? 1),
            'X-TR-Total' => (string) ($meta['total'] ?? 0),
        ];
    }

    public static function serve_request_headers($served, $result, $request, $server) {
        $route = (string) $request->get_route();
        if (!str_starts_with($route, '/technorapper/v1/')) {
            return $served;
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        $status = 200;
        if ($result instanceof WP_REST_Response) {
            $status = (int) $result->get_status();
        } elseif ($result instanceof WP_Error) {
            $status = (int) ($result->get_error_data()['status'] ?? 500);
            $headers = $result->get_error_data()['headers'] ?? [];
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    header($name . ': ' . $value);
                }
            }
        }

        if ($status !== 200 || str_contains($route, '/auth/token')) {
            header('Cache-Control: no-store');
        } else {
            // Authenticated responses: private. Edge may still cache after its own auth rules.
            header('Cache-Control: private, max-age=30, stale-while-revalidate=60');
            header('Vary: Authorization');
        }

        return $served;
    }
}
