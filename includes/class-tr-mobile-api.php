<?php
if (!defined('ABSPATH')) exit;

final class TR_Mobile_API {
    public function register_routes(): void {
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/config', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'config'],
            'permission_callback' => [$this, 'permission'],
        ]);
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/desks', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'desks'],
            'permission_callback' => [$this, 'permission'],
        ]);
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/home', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'home'],
            'permission_callback' => [$this, 'permission'],
        ]);
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/posts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'posts'],
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'page' => ['default' => 1, 'sanitize_callback' => 'absint'],
                'limit' => ['default' => 20, 'sanitize_callback' => 'absint'],
                'desk' => ['default' => '', 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/posts/(?P<id>\\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'post'],
            'permission_callback' => [$this, 'permission'],
        ]);
        register_rest_route(TR_MOBILE_API_NAMESPACE, '/search', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'search'],
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'q' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'page' => ['default' => 1, 'sanitize_callback' => 'absint'],
                'limit' => ['default' => 20, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    public function permission(WP_REST_Request $request) {
        $auth = TR_Mobile_Auth::authenticate($request);
        if (is_wp_error($auth)) return $auth;
        $rate = TR_Mobile_Rate_Limit::check($request);
        if (is_wp_error($rate)) return $rate;
        return true;
    }

    public function config(): WP_REST_Response {
        return new WP_REST_Response([
            'api_version' => 'v1',
            'plugin_version' => TR_MOBILE_API_VERSION,
            'min_app_version' => get_option('tr_mobile_min_app_version', '1.0.0'),
            'desks' => $this->desk_payload(),
            'features' => [
                'push_notifications' => true,
                'search' => true,
            ],
        ], 200);
    }

    public function desks(): WP_REST_Response {
        return new WP_REST_Response(['data' => $this->desk_payload()], 200);
    }

    public function home(WP_REST_Request $request): WP_REST_Response {
        $limit = min(max((int) $request->get_param('limit') ?: 20, 1), 20);
        $posts = $this->query_posts(1, $limit);
        return new WP_REST_Response([
            'data' => [
                'hero' => array_slice($posts['items'], 0, 1),
                'latest' => $posts['items'],
                'desks' => $this->desk_payload(),
            ],
            'meta' => ['generated_at' => gmdate('c')],
        ], 200);
    }

    public function posts(WP_REST_Request $request): WP_REST_Response {
        $page = max(1, (int) $request->get_param('page'));
        $limit = min(max((int) $request->get_param('limit'), 1), 20);
        $desk = (string) $request->get_param('desk');
        $result = $this->query_posts($page, $limit, $desk);
        return new WP_REST_Response($result, 200, $this->pagination_headers($result));
    }

    public function post(WP_REST_Request $request) {
        $post = get_post((int) $request['id']);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            return new WP_Error('tr_not_found', 'Post not found.', ['status' => 404]);
        }
        return new WP_REST_Response(['data' => $this->serialize_post($post, true)], 200);
    }

    public function search(WP_REST_Request $request) {
        $q = trim((string) $request->get_param('q'));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            return new WP_Error('tr_invalid_query', 'Search query must be between 2 and 100 characters.', ['status' => 400]);
        }
        $page = max(1, (int) $request->get_param('page'));
        $limit = min(max((int) $request->get_param('limit'), 1), 20);
        $query = new WP_Query([
            'post_type' => 'post', 'post_status' => 'publish', 's' => $q,
            'paged' => $page, 'posts_per_page' => $limit, 'ignore_sticky_posts' => true,
        ]);
        $items = array_map(fn($p) => $this->serialize_post($p), $query->posts);
        return new WP_REST_Response([
            'data' => $items,
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages],
        ], 200, $this->pagination_headers_from_query($query));
    }

    private function query_posts(int $page, int $limit, string $desk = ''): array {
        $args = ['post_type' => 'post', 'post_status' => 'publish', 'paged' => $page, 'posts_per_page' => $limit, 'ignore_sticky_posts' => true];
        if ($desk !== '') $args['category_name'] = $desk;
        $query = new WP_Query($args);
        $items = array_map(fn($p) => $this->serialize_post($p), $query->posts);
        return [
            'data' => $items,
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages],
            'items' => $items,
        ];
    }

    private function serialize_post(WP_Post $post, bool $full = false): array {
        $categories = get_the_category($post->ID);
        $image = get_the_post_thumbnail_url($post->ID, 'technorapper-card') ?: get_the_post_thumbnail_url($post->ID, 'full');
        $item = [
            'id' => (int) $post->ID,
            'slug' => $post->post_name,
            'title' => get_the_title($post),
            'excerpt' => wp_strip_all_tags(get_the_excerpt($post)),
            'date' => get_post_time('c', true, $post),
            'url' => get_permalink($post),
            'canonical_url' => get_permalink($post),
            'image' => $image ? esc_url_raw($image) : null,
            'desk' => !empty($categories) ? ['id' => (int) $categories[0]->term_id, 'slug' => $categories[0]->slug, 'name' => $categories[0]->name] : null,
        ];
        if ($full) {
            $item['content'] = apply_filters('the_content', $post->post_content);
            $item['ai_wire'] = $this->ai_wire_disclosure($post->ID);
            $item['related'] = $this->related_posts($post->ID, $categories[0]->term_id ?? 0);
        }
        return $item;
    }

    private function related_posts(int $post_id, int $category_id): array {
        if (!$category_id) return [];
        $q = new WP_Query(['post_type'=>'post','post_status'=>'publish','posts_per_page'=>5,'cat'=>$category_id,'post__not_in'=>[$post_id],'ignore_sticky_posts'=>true]);
        return array_map(fn($p) => $this->serialize_post($p), $q->posts);
    }

    private function ai_wire_disclosure(int $post_id): ?array {
        $value = get_post_meta($post_id, '_tr_ai_wire_provenance', true);
        if (is_array($value)) return $value;
        return null;
    }

    private function desk_payload(): array {
        $slugs = get_theme_mod('desk_slugs', '');
        $slugs = array_values(array_filter(array_map('sanitize_title', explode(',', (string)$slugs))));
        if (!$slugs) {
            $defaults = ['apple','ai','android','auto','pc-and-hardware','reviews','comics','guides','news'];
            $slugs = array_values(array_filter($defaults, fn($slug) => term_exists($slug, 'category')));
        }
        $items = [];
        foreach ($slugs as $order => $slug) {
            $term = get_term_by('slug', $slug, 'category');
            if (!$term || is_wp_error($term)) continue;
            $items[] = ['id'=>(int)$term->term_id,'slug'=>$term->slug,'name'=>$term->name,'order'=>$order];
        }
        return $items;
    }

    private function pagination_headers(array $result): array {
        $meta = $result['meta'] ?? [];
        return ['X-TR-Page' => (string)($meta['page'] ?? 1), 'X-TR-Pages' => (string)($meta['pages'] ?? 1), 'X-TR-Total' => (string)($meta['total'] ?? 0)];
    }

    private function pagination_headers_from_query(WP_Query $q): array {
        return ['X-TR-Page'=>(string)$q->get('paged'),'X-TR-Pages'=>(string)$q->max_num_pages,'X-TR-Total'=>(string)$q->found_posts];
    }

    public static function serve_request_headers($served, $result, $request, $server) {
        if (str_starts_with($request->get_route(), '/technorapper/v1/')) {
            header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
        }
        return $served;
    }
}
