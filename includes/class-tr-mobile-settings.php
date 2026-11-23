<?php
if (!defined('ABSPATH')) exit;

final class TR_Mobile_Settings {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_notices', [__CLASS__, 'notice_if_unconfigured']);
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

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $app_key = TR_Mobile_Auth::configured_app_key();
        $ttl = TR_Mobile_Auth::token_ttl();
        $min = (string) get_option('tr_mobile_min_app_version', '1.0.0');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('TechnoRapper Mobile API', 'technorapper-mobile-api'); ?></h1>
            <p><?php echo esc_html__('The mobile app exchanges the App Key for a short-lived Bearer token, then uses that token on all content routes.', 'technorapper-mobile-api'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('tr_mobile_api'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tr_mobile_app_key"><?php echo esc_html__('App Key', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(TR_Mobile_Auth::OPTION_APP_KEY); ?>" type="text" id="tr_mobile_app_key" value="<?php echo esc_attr($app_key); ?>" class="regular-text" autocomplete="off" />
                            <p class="description"><?php echo esc_html__('Required. Embed the same value in the official app build. Rotate if leaked. Not a WordPress password.', 'technorapper-mobile-api'); ?></p>
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
            <h2><?php echo esc_html__('App usage', 'technorapper-mobile-api'); ?></h2>
            <ol>
                <li><code>POST /wp-json/technorapper/v1/auth/token</code> with header <code>X-TR-App-Key</code></li>
                <li>All other routes: <code>Authorization: Bearer &lt;access_token&gt;</code></li>
            </ol>
        </div>
        <?php
    }
}
