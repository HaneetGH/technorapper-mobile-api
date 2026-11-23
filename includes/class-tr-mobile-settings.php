<?php
if (!defined('ABSPATH')) exit;

final class TR_Mobile_Settings {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_notices', [__CLASS__, 'notice_if_unconfigured']);
        add_action('admin_post_tr_mobile_generate_token', [__CLASS__, 'handle_generate']);
        add_action('admin_post_tr_mobile_revoke_install', [__CLASS__, 'handle_revoke']);
        add_action('admin_post_tr_mobile_reactivate_install', [__CLASS__, 'handle_reactivate']);
    }

    public static function menu(): void {
        add_options_page(
            'TechnoRapper Mobile API',
            'TR Mobile API',
            'manage_options',
            'tr-mobile-api',
            [__CLASS__, 'render']
        );
    }

    public static function register(): void {
        register_setting('tr_mobile_api', 'tr_mobile_min_app_version', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '1.0.0',
        ]);
        register_setting('tr_mobile_api', TR_Mobile_Auth::OPTION_TOKEN_TTL, [
            'type' => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_ttl'],
            'default' => 3600,
        ]);
    }

    public static function sanitize_ttl($value): int {
        $ttl = (int) $value;
        return min(max($ttl, 300), 86400);
    }

    public static function notice_if_unconfigured(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (TR_Mobile_Auth::configured_app_key() !== '') {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && isset($screen->id) && $screen->id === 'settings_page_tr-mobile-api') {
            return;
        }
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('TechnoRapper Mobile API needs a Mobile API Token. Open Settings → TR Mobile API and click Generate.', 'technorapper-mobile-api');
        echo '</p></div>';
    }

    public static function handle_generate(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'technorapper-mobile-api'));
        }
        check_admin_referer('tr_mobile_generate_token');
        TR_Mobile_Auth::generate_mobile_token();
        wp_safe_redirect(add_query_arg([
            'page' => 'tr-mobile-api',
            'tr_notice' => 'generated',
        ], admin_url('options-general.php')));
        exit;
    }

    public static function handle_revoke(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'technorapper-mobile-api'));
        }
        check_admin_referer('tr_mobile_revoke_install');
        $install_id = isset($_GET['install_id']) ? sanitize_text_field(wp_unslash((string) $_GET['install_id'])) : '';
        if ($install_id !== '') {
            TR_Mobile_Installs::revoke($install_id);
        }
        wp_safe_redirect(add_query_arg([
            'page' => 'tr-mobile-api',
            'tr_notice' => 'revoked',
        ], admin_url('options-general.php')));
        exit;
    }

    public static function handle_reactivate(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'technorapper-mobile-api'));
        }
        check_admin_referer('tr_mobile_reactivate_install');
        $install_id = isset($_GET['install_id']) ? sanitize_text_field(wp_unslash((string) $_GET['install_id'])) : '';
        if ($install_id !== '') {
            TR_Mobile_Installs::reactivate($install_id);
        }
        wp_safe_redirect(add_query_arg([
            'page' => 'tr-mobile-api',
            'tr_notice' => 'reactivated',
        ], admin_url('options-general.php')));
        exit;
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $app_key = TR_Mobile_Auth::configured_app_key();
        $ttl = TR_Mobile_Auth::token_ttl();
        $min = (string) get_option('tr_mobile_min_app_version', '1.0.0');
        $status_filter = isset($_GET['install_status']) ? sanitize_key((string) $_GET['install_status']) : '';
        $page = isset($_GET['install_page']) ? max(1, (int) $_GET['install_page']) : 1;
        $list = TR_Mobile_Installs::list($page, 20, $status_filter);
        $pages = max(1, (int) ceil($list['total'] / 20));
        $notice = isset($_GET['tr_notice']) ? sanitize_key((string) $_GET['tr_notice']) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('TechnoRapper Mobile API', 'technorapper-mobile-api'); ?></h1>
            <?php if ($notice === 'generated'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Mobile API Token generated. Copy it into your mobile app now.', 'technorapper-mobile-api'); ?></p></div>
            <?php elseif ($notice === 'revoked'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Install revoked.', 'technorapper-mobile-api'); ?></p></div>
            <?php elseif ($notice === 'reactivated'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Install reactivated.', 'technorapper-mobile-api'); ?></p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__('Mobile API Token', 'technorapper-mobile-api'); ?></h2>
            <p><?php echo esc_html__('Click Generate. The plugin creates the token (like GitHub). Put that token in your mobile app and send it as Authorization: Bearer on every content request.', 'technorapper-mobile-api'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tr_mobile_api_token"><?php echo esc_html__('Token', 'technorapper-mobile-api'); ?></label></th>
                    <td>
                        <input type="text" id="tr_mobile_api_token" class="large-text code" readonly value="<?php echo esc_attr($app_key); ?>" onclick="this.select();" autocomplete="off" />
                        <p class="description">
                            <?php if ($app_key === ''): ?>
                                <?php echo esc_html__('No token yet. Click Generate Mobile API Token.', 'technorapper-mobile-api'); ?>
                            <?php else: ?>
                                <?php echo esc_html__('Click the field to select, then copy. Regenerating replaces the old token and will break apps still using it.', 'technorapper-mobile-api'); ?>
                            <?php endif; ?>
                        </p>
                        <p>
                            <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(
                                admin_url('admin-post.php?action=tr_mobile_generate_token'),
                                'tr_mobile_generate_token'
                            )); ?>" onclick="return confirm('<?php echo esc_js($app_key === ''
                                ? __('Generate a new Mobile API Token?', 'technorapper-mobile-api')
                                : __('Regenerate token? The current token will stop working in the mobile app.', 'technorapper-mobile-api')
                            ); ?>');">
                                <?php echo esc_html($app_key === '' ? __('Generate Mobile API Token', 'technorapper-mobile-api') : __('Regenerate Mobile API Token', 'technorapper-mobile-api')); ?>
                            </a>
                        </p>
                        <?php if ($app_key !== ''): ?>
                            <p><code>Authorization: Bearer <?php echo esc_html($app_key); ?></code></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <form method="post" action="options.php">
                <?php settings_fields('tr_mobile_api'); ?>
                <h2><?php echo esc_html__('Optional settings', 'technorapper-mobile-api'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tr_mobile_token_ttl"><?php echo esc_html__('Install token TTL (seconds)', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(TR_Mobile_Auth::OPTION_TOKEN_TTL); ?>" type="number" id="tr_mobile_token_ttl" value="<?php echo esc_attr((string) $ttl); ?>" min="300" max="86400" class="small-text" />
                            <p class="description"><?php echo esc_html__('Only for advanced per-install short-lived tokens. 300–86400. Default 3600.', 'technorapper-mobile-api'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_min_app_version"><?php echo esc_html__('Minimum app version', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="tr_mobile_min_app_version" type="text" id="tr_mobile_min_app_version" value="<?php echo esc_attr($min); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save optional settings', 'technorapper-mobile-api')); ?>
            </form>

            <hr />
            <h2><?php echo esc_html__('App usage (simple)', 'technorapper-mobile-api'); ?></h2>
            <ol>
                <li><?php echo esc_html__('Generate Mobile API Token above.', 'technorapper-mobile-api'); ?></li>
                <li><?php echo esc_html__('Put the token in the mobile app.', 'technorapper-mobile-api'); ?></li>
                <li><code>GET /wp-json/technorapper/v1/posts</code> with header <code>Authorization: Bearer &lt;token&gt;</code></li>
            </ol>

            <hr />
            <h2><?php echo esc_html__('Registered installs (advanced)', 'technorapper-mobile-api'); ?></h2>
            <p><?php echo esc_html__('Optional per-device credentials via /auth/register. Use when you need to revoke one phone without regenerating the shared Mobile API Token.', 'technorapper-mobile-api'); ?></p>
            <p>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=tr-mobile-api')); ?>"><?php echo esc_html__('All', 'technorapper-mobile-api'); ?></a>
                |
                <a href="<?php echo esc_url(admin_url('options-general.php?page=tr-mobile-api&install_status=active')); ?>"><?php echo esc_html__('Active', 'technorapper-mobile-api'); ?></a>
                |
                <a href="<?php echo esc_url(admin_url('options-general.php?page=tr-mobile-api&install_status=revoked')); ?>"><?php echo esc_html__('Revoked', 'technorapper-mobile-api'); ?></a>
                — <?php echo esc_html(sprintf(/* translators: %d: count */ __('Total: %d', 'technorapper-mobile-api'), $list['total'])); ?>
            </p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Install ID', 'technorapper-mobile-api'); ?></th>
                        <th><?php echo esc_html__('Status', 'technorapper-mobile-api'); ?></th>
                        <th><?php echo esc_html__('Platform', 'technorapper-mobile-api'); ?></th>
                        <th><?php echo esc_html__('App', 'technorapper-mobile-api'); ?></th>
                        <th><?php echo esc_html__('Label', 'technorapper-mobile-api'); ?></th>
                        <th><?php echo esc_html__('Created (UTC)', 'technorapper-mobile-api'); ?></th>
                        <th><?php echo esc_html__('Last seen (UTC)', 'technorapper-mobile-api'); ?></th>
                        <th><?php echo esc_html__('Last IP', 'technorapper-mobile-api'); ?></th>
                        <th><?php echo esc_html__('Actions', 'technorapper-mobile-api'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$list['items']): ?>
                    <tr><td colspan="9"><?php echo esc_html__('No installs registered yet.', 'technorapper-mobile-api'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($list['items'] as $row): ?>
                        <tr>
                            <td><code><?php echo esc_html((string) $row->install_id); ?></code></td>
                            <td><?php echo esc_html((string) $row->status); ?></td>
                            <td><?php echo esc_html((string) $row->platform); ?></td>
                            <td><?php echo esc_html((string) $row->app_version); ?></td>
                            <td><?php echo esc_html((string) $row->device_label); ?></td>
                            <td><?php echo esc_html((string) $row->created_at); ?></td>
                            <td><?php echo esc_html((string) ($row->last_seen_at ?: '—')); ?></td>
                            <td><?php echo esc_html((string) ($row->last_ip ?: '—')); ?></td>
                            <td>
                                <?php if (($row->status ?? '') === TR_Mobile_Installs::STATUS_ACTIVE): ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin-post.php?action=tr_mobile_revoke_install&install_id=' . rawurlencode((string) $row->install_id)),
                                        'tr_mobile_revoke_install'
                                    )); ?>"><?php echo esc_html__('Revoke', 'technorapper-mobile-api'); ?></a>
                                <?php else: ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin-post.php?action=tr_mobile_reactivate_install&install_id=' . rawurlencode((string) $row->install_id)),
                                        'tr_mobile_reactivate_install'
                                    )); ?>"><?php echo esc_html__('Reactivate', 'technorapper-mobile-api'); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($pages > 1): ?>
                <p>
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <a href="<?php echo esc_url(add_query_arg([
                            'page' => 'tr-mobile-api',
                            'install_status' => $status_filter,
                            'install_page' => $i,
                        ], admin_url('options-general.php'))); ?>"><?php echo (int) $i; ?></a>
                    <?php endfor; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
