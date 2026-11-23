<?php
if (!defined('ABSPATH')) exit;

final class TR_Mobile_Settings {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_notices', [__CLASS__, 'notice_if_unconfigured']);
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
        register_setting('tr_mobile_api', TR_Mobile_Auth::OPTION_APP_KEY, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_app_key'],
            'default' => '',
        ]);
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

    public static function sanitize_app_key($value): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        return sanitize_text_field($value);
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
        echo esc_html__('TechnoRapper Mobile API is fail-closed until you set an App Key under Settings → TR Mobile API.', 'technorapper-mobile-api');
        echo '</p></div>';
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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('TechnoRapper Mobile API', 'technorapper-mobile-api'); ?></h1>
            <?php if (!empty($_GET['tr_notice'])): ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    echo esc_html(
                        $_GET['tr_notice'] === 'reactivated'
                            ? __('Install reactivated.', 'technorapper-mobile-api')
                            : __('Install revoked.', 'technorapper-mobile-api')
                    );
                    ?>
                </p></div>
            <?php endif; ?>
            <p><?php echo esc_html__('Each device registers once, then mints short-lived Bearer tokens. Revoke an install to cut off a compromised or abusive client.', 'technorapper-mobile-api'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('tr_mobile_api'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tr_mobile_app_key"><?php echo esc_html__('App Key', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(TR_Mobile_Auth::OPTION_APP_KEY); ?>" type="text" id="tr_mobile_app_key" value="<?php echo esc_attr($app_key); ?>" class="regular-text" autocomplete="off" />
                            <p class="description"><?php echo esc_html__('Required for POST /auth/register only. Embed in the official app build.', 'technorapper-mobile-api'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_token_ttl"><?php echo esc_html__('Token TTL (seconds)', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(TR_Mobile_Auth::OPTION_TOKEN_TTL); ?>" type="number" id="tr_mobile_token_ttl" value="<?php echo esc_attr((string) $ttl); ?>" min="300" max="86400" class="small-text" />
                            <p class="description"><?php echo esc_html__('300–86400. Default 3600.', 'technorapper-mobile-api'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_min_app_version"><?php echo esc_html__('Minimum app version', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="tr_mobile_min_app_version" type="text" id="tr_mobile_min_app_version" value="<?php echo esc_attr($min); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php echo esc_html__('Registered installs', 'technorapper-mobile-api'); ?></h2>
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

            <hr />
            <h2><?php echo esc_html__('App usage', 'technorapper-mobile-api'); ?></h2>
            <ol>
                <li><code>POST /wp-json/technorapper/v1/auth/register</code> with header <code>X-TR-App-Key</code> → store <code>install_id</code> + <code>install_secret</code></li>
                <li><code>POST /wp-json/technorapper/v1/auth/token</code> with JSON <code>install_id</code> + <code>install_secret</code></li>
                <li>All content routes: <code>Authorization: Bearer &lt;access_token&gt;</code></li>
            </ol>
        </div>
        <?php
    }
}
