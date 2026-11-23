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
        add_submenu_page(
            'options-general.php',
            'Mobile API Docs',
            'TR Mobile API Docs',
            'manage_options',
            'tr-mobile-api-docs',
            ['TR_Mobile_Docs', 'render_admin_page']
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
        register_setting('tr_mobile_api', TR_Mobile_App_Lock::OPTION_ANDROID_PACKAGE, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'com.technorapper.app',
        ]);
        register_setting('tr_mobile_api', TR_Mobile_App_Lock::OPTION_ANDROID_CERTS, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => '',
        ]);
        register_setting('tr_mobile_api', TR_Mobile_App_Lock::OPTION_IOS_BUNDLE, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'com.technorapper.app',
        ]);
        register_setting('tr_mobile_api', TR_Mobile_App_Lock::OPTION_REQUIRE_ATTESTATION, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_bool_flag'],
            'default' => '0',
        ]);
        register_setting('tr_mobile_api', TR_Mobile_App_Lock::OPTION_ACCEPT_SHARED_BEARER, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_bool_flag'],
            'default' => '1',
        ]);
        register_setting('tr_mobile_api', TR_Mobile_Attestation::OPTION_GOOGLE_SA_JSON, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_sa_json'],
            'default' => '',
            'show_in_rest' => false,
        ]);
        register_setting('tr_mobile_api', TR_Mobile_Attestation::OPTION_PLAY_CLOUD_PROJECT, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
        register_setting('tr_mobile_api', TR_Mobile_Attestation::OPTION_APPLE_TEAM_ID, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
        register_setting('tr_mobile_api', TR_Mobile_Attestation::OPTION_APPLE_APP_ID, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
        register_setting('tr_mobile_api', TR_Mobile_Attestation::OPTION_STAGING_IPS, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => '',
        ]);
        register_setting('tr_mobile_api', TR_Mobile_Attestation::OPTION_ATTEST_ON_TOKEN, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_bool_flag'],
            // Default OFF: register-only Integrity stays within Google's free daily quota.
            'default' => '0',
        ]);
    }

    public static function sanitize_bool_flag($value): string {
        return ((string) $value === '1' || $value === 1 || $value === true || $value === 'on') ? '1' : '0';
    }

    public static function sanitize_ttl($value): int {
        $ttl = (int) $value;
        return min(max($ttl, 300), 86400);
    }

    public static function sanitize_sa_json($value): string {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            add_settings_error(
                'tr_mobile_api',
                'tr_sa_json',
                __('Google service account JSON must include client_email and private_key.', 'technorapper-mobile-api'),
                'error'
            );
            return (string) get_option(TR_Mobile_Attestation::OPTION_GOOGLE_SA_JSON, '');
        }
        return $value;
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
        settings_errors('tr_mobile_api');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('TechnoRapper Mobile API', 'technorapper-mobile-api'); ?></h1>
            <?php if ($notice === 'generated'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Mobile API Token generated. Use it for staging / Generate button only — store builds should use install JWTs.', 'technorapper-mobile-api'); ?></p></div>
            <?php elseif ($notice === 'revoked'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Install revoked.', 'technorapper-mobile-api'); ?></p></div>
            <?php elseif ($notice === 'reactivated'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Install reactivated.', 'technorapper-mobile-api'); ?></p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__('Mobile API Token', 'technorapper-mobile-api'); ?></h2>
            <p><?php echo esc_html__('Server secret used for fail-closed config and staging. Production store builds should register with Play Integrity / App Attest and use short-lived install JWTs — turn off “Accept shared Bearer”.', 'technorapper-mobile-api'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tr_mobile_api_token"><?php echo esc_html__('Token', 'technorapper-mobile-api'); ?></label></th>
                    <td>
                        <input type="text" id="tr_mobile_api_token" class="large-text code" readonly value="<?php echo esc_attr($app_key); ?>" onclick="this.select();" autocomplete="off" />
                        <p class="description">
                            <?php if ($app_key === ''): ?>
                                <?php echo esc_html__('No token yet. Click Generate Mobile API Token.', 'technorapper-mobile-api'); ?>
                            <?php else: ?>
                                <?php echo esc_html__('Click the field to select, then copy. Regenerating replaces the old token.', 'technorapper-mobile-api'); ?>
                            <?php endif; ?>
                        </p>
                        <p>
                            <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(
                                admin_url('admin-post.php?action=tr_mobile_generate_token'),
                                'tr_mobile_generate_token'
                            )); ?>" onclick="return confirm('<?php echo esc_js($app_key === ''
                                ? __('Generate a new Mobile API Token?', 'technorapper-mobile-api')
                                : __('Regenerate token? The current token will stop working where still used.', 'technorapper-mobile-api')
                            ); ?>');">
                                <?php echo esc_html($app_key === '' ? __('Generate Mobile API Token', 'technorapper-mobile-api') : __('Regenerate Mobile API Token', 'technorapper-mobile-api')); ?>
                            </a>
                        </p>
                    </td>
                </tr>
            </table>

            <form method="post" action="options.php">
                <?php
                settings_fields('tr_mobile_api');
                $android_pkg = TR_Mobile_App_Lock::allowed_android_package();
                $ios_bundle = TR_Mobile_App_Lock::allowed_ios_bundle();
                $certs = (string) get_option(TR_Mobile_App_Lock::OPTION_ANDROID_CERTS, '');
                $require_att = TR_Mobile_App_Lock::require_attestation();
                $accept_shared = TR_Mobile_App_Lock::accept_shared_bearer();
                $sa_json = (string) get_option(TR_Mobile_Attestation::OPTION_GOOGLE_SA_JSON, '');
                $project = (string) get_option(TR_Mobile_Attestation::OPTION_PLAY_CLOUD_PROJECT, '');
                $team = (string) get_option(TR_Mobile_Attestation::OPTION_APPLE_TEAM_ID, '');
                $apple_app = (string) get_option(TR_Mobile_Attestation::OPTION_APPLE_APP_ID, '');
                $staging_ips = (string) get_option(TR_Mobile_Attestation::OPTION_STAGING_IPS, '');
                $attest_token = (string) get_option(TR_Mobile_Attestation::OPTION_ATTEST_ON_TOKEN, '0') === '1';
                ?>
                <h2><?php echo esc_html__('App-only access (production)', 'technorapper-mobile-api'); ?></h2>
                <p><?php echo esc_html__('Locks the API to your official app. For Play Store: turn OFF shared Bearer, turn ON attestation, configure Google/Apple credentials below.', 'technorapper-mobile-api'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tr_mobile_allowed_android_package"><?php echo esc_html__('Allowed Android package', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(TR_Mobile_App_Lock::OPTION_ANDROID_PACKAGE); ?>" type="text" id="tr_mobile_allowed_android_package" value="<?php echo esc_attr($android_pkg); ?>" class="regular-text code" />
                            <p class="description"><?php echo esc_html__('Must match applicationId. Register requires nonce from GET /auth/challenge.', 'technorapper-mobile-api'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_allowed_android_cert_sha256"><?php echo esc_html__('Allowed Android cert SHA-256', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <textarea name="<?php echo esc_attr(TR_Mobile_App_Lock::OPTION_ANDROID_CERTS); ?>" id="tr_mobile_allowed_android_cert_sha256" rows="3" class="large-text code"><?php echo esc_textarea($certs); ?></textarea>
                            <p class="description"><?php echo esc_html__('Play App signing certificate digests (comma or newline separated hex). Checked against Integrity token digests.', 'technorapper-mobile-api'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_allowed_ios_bundle_id"><?php echo esc_html__('Allowed iOS bundle ID', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(TR_Mobile_App_Lock::OPTION_IOS_BUNDLE); ?>" type="text" id="tr_mobile_allowed_ios_bundle_id" value="<?php echo esc_attr($ios_bundle); ?>" class="regular-text code" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Accept shared Bearer (tr_mob_…)', 'technorapper-mobile-api'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(TR_Mobile_App_Lock::OPTION_ACCEPT_SHARED_BEARER); ?>" value="0" />
                            <label>
                                <input name="<?php echo esc_attr(TR_Mobile_App_Lock::OPTION_ACCEPT_SHARED_BEARER); ?>" type="checkbox" value="1" <?php checked($accept_shared); ?> />
                                <?php echo esc_html__('Allow long-lived Mobile API Token on content routes (turn OFF for production app-only)', 'technorapper-mobile-api'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Require Play Integrity / App Attest', 'technorapper-mobile-api'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(TR_Mobile_App_Lock::OPTION_REQUIRE_ATTESTATION); ?>" value="0" />
                            <label>
                                <input name="<?php echo esc_attr(TR_Mobile_App_Lock::OPTION_REQUIRE_ATTESTATION); ?>" type="checkbox" value="1" <?php checked($require_att); ?> />
                                <?php echo esc_html__('Register requires verified attestation (turn ON for store)', 'technorapper-mobile-api'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Attestation on /auth/token', 'technorapper-mobile-api'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(TR_Mobile_Attestation::OPTION_ATTEST_ON_TOKEN); ?>" value="0" />
                            <label>
                                <input name="<?php echo esc_attr(TR_Mobile_Attestation::OPTION_ATTEST_ON_TOKEN); ?>" type="checkbox" value="1" <?php checked($attest_token); ?> />
                                <?php echo esc_html__('Also require Integrity/App Attest on every token mint (leave OFF for free apps — uses Play’s free daily quota faster)', 'technorapper-mobile-api'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('Play Integrity and App Attest have no per-request fee. Default is register-only so you stay within Google’s free ~10k/day quota.', 'technorapper-mobile-api'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_token_ttl"><?php echo esc_html__('Install token TTL (seconds)', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(TR_Mobile_Auth::OPTION_TOKEN_TTL); ?>" type="number" id="tr_mobile_token_ttl" value="<?php echo esc_attr((string) $ttl); ?>" min="300" max="86400" class="small-text" />
                            <p class="description"><?php echo esc_html__('Short-lived install JWTs. 300–86400. Default 3600.', 'technorapper-mobile-api'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_min_app_version"><?php echo esc_html__('Minimum app version', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="tr_mobile_min_app_version" type="text" id="tr_mobile_min_app_version" value="<?php echo esc_attr($min); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Attestation credentials', 'technorapper-mobile-api'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tr_mobile_play_cloud_project_number"><?php echo esc_html__('Play Cloud project number', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(TR_Mobile_Attestation::OPTION_PLAY_CLOUD_PROJECT); ?>" type="text" id="tr_mobile_play_cloud_project_number" value="<?php echo esc_attr($project); ?>" class="regular-text code" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_google_sa_json"><?php echo esc_html__('Google service account JSON', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <textarea name="<?php echo esc_attr(TR_Mobile_Attestation::OPTION_GOOGLE_SA_JSON); ?>" id="tr_mobile_google_sa_json" rows="6" class="large-text code" autocomplete="off"><?php echo esc_textarea($sa_json); ?></textarea>
                            <p class="description"><?php echo esc_html__('Service account with Play Integrity API access. Stored in wp_options (not autoloaded via REST).', 'technorapper-mobile-api'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_apple_team_id"><?php echo esc_html__('Apple Team ID', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(TR_Mobile_Attestation::OPTION_APPLE_TEAM_ID); ?>" type="text" id="tr_mobile_apple_team_id" value="<?php echo esc_attr($team); ?>" class="regular-text code" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_apple_app_id"><?php echo esc_html__('Apple App ID', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr(TR_Mobile_Attestation::OPTION_APPLE_APP_ID); ?>" type="text" id="tr_mobile_apple_app_id" value="<?php echo esc_attr($apple_app); ?>" class="regular-text code" />
                            <p class="description"><?php echo esc_html__('Full App Attest App ID: TeamID.bundleId (e.g. ABCD1234.com.technorapper.app).', 'technorapper-mobile-api'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tr_mobile_staging_register_ips"><?php echo esc_html__('Staging register IP allowlist', 'technorapper-mobile-api'); ?></label></th>
                        <td>
                            <textarea name="<?php echo esc_attr(TR_Mobile_Attestation::OPTION_STAGING_IPS); ?>" id="tr_mobile_staging_register_ips" rows="2" class="large-text code"><?php echo esc_textarea($staging_ips); ?></textarea>
                            <p class="description"><?php echo esc_html__('When attestation is ON, these IPs may register with X-TR-App-Key + nonce (no Integrity). Empty = off. Emulators / CI only.', 'technorapper-mobile-api'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save app-only settings', 'technorapper-mobile-api')); ?>
            </form>

            <hr />
            <h2><?php echo esc_html__('Production flow', 'technorapper-mobile-api'); ?></h2>
            <ol>
                <li><code>GET /auth/challenge</code> → nonce</li>
                <li><code>POST /auth/register</code> with package + Play Integrity / App Attest</li>
                <li><code>POST /auth/token</code> → short-lived Bearer</li>
                <li>Content routes with <code>Authorization: Bearer &lt;install JWT&gt;</code></li>
            </ol>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url(admin_url('options-general.php?page=tr-mobile-api-docs')); ?>">
                    <?php echo esc_html__('Open full API documentation', 'technorapper-mobile-api'); ?>
                </a>
            </p>

            <hr />
            <h2><?php echo esc_html__('Registered installs', 'technorapper-mobile-api'); ?></h2>
            <p><?php echo esc_html__('Per-device credentials. Revoke one phone without rotating the shared token or forcing every user to reinstall.', 'technorapper-mobile-api'); ?></p>
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
