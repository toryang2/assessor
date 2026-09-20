<?php
/**
 * Assessor Security Setup
 *
 * Handles deployment-specific security initialization for the Assessor plugin.
 * 
 * Architectural & Security Principles:
 * - JWT Secret in wp-config.php:
 *   The JWT signing key authenticates API tokens across the system and belongs strictly
 *   in the installed site's wp-config.php as a constant (JWT_AUTH_SECRET_KEY). Placing it
 *   in wp-config.php keeps it outside web root, away from database dumps/syncs, and prevents
 *   exposure to other WordPress components or plugins.
 * 
 * - Never Store JWT Secret Permanently in wp_options:
 *   The Assessor system features database sync, export routines, and database migrations.
 *   Storing secrets in wp_options would risk syncing secrets across deployments or leaking
 *   them through database backups and exports.
 * 
 * - Administrative Protection & Nonces:
 *   The setup screen and initialization actions are strictly protected by checking
 *   current_user_can('manage_options') independently and validating WordPress nonces via
 *   check_admin_referer to prevent CSRF and unauthorized execution.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Assessor_Security_Setup {

    /**
     * Transient key prefix for temporary manual setup fallback.
     */
    const TRANSIENT_PREFIX = 'assessor_jwt_bootstrap_';

    /**
     * Transient key prefix for temporary manual reinitialization fallback.
     */
    const REINIT_TRANSIENT_PREFIX = 'assessor_deployment_reinit_';

    /**
     * Initialize WordPress hooks for security setup.
     */
    public static function init() {
        // Ensure installation ID exists
        self::ensure_installation_id();

        // Admin menu
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));

        // Admin post handlers for form submissions
        add_action('admin_post_assessor_initialize_security', array(__CLASS__, 'handle_initialize_security'));
        add_action('admin_post_assessor_reinitialize_deployment', array(__CLASS__, 'handle_reinitialize'));
        add_action('admin_post_assessor_verify_reinitialization', array(__CLASS__, 'handle_verify_reinitialization'));
        add_action('admin_post_assessor_cancel_reinitialization', array(__CLASS__, 'handle_cancel_reinitialization'));

        // Admin notice if JWT secret is not configured
        add_action('admin_notices', array(__CLASS__, 'render_admin_notice'));
    }

    /**
     * Check whether JWT_AUTH_SECRET_KEY is configured and non-empty.
     *
     * @return bool
     */
    public static function is_jwt_configured() {
        return defined('JWT_AUTH_SECRET_KEY') && !empty(JWT_AUTH_SECRET_KEY);
    }

    /**
     * Get the unique installation ID for this deployment.
     *
     * @return string
     */
    public static function get_installation_id() {
        return (string) get_option('assessor_installation_id', '');
    }

    /**
     * Generate a new, non-colliding UUID installation identifier.
     *
     * @param string $exclude_id ID that must not be matched.
     * @return string
     */
    public static function generate_installation_uuid($exclude_id = '') {
        do {
            if (function_exists('wp_generate_uuid4')) {
                $new_id = wp_generate_uuid4();
            } elseif (class_exists('Assessor_UUID')) {
                $new_id = Assessor_UUID::v7();
            } else {
                $new_id = sprintf(
                    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );
            }
        } while (!empty($exclude_id) && $new_id === $exclude_id);

        return $new_id;
    }

    /**
     * Ensure this deployment has a persistent, unique non-secret installation ID.
     *
     * @return string
     */
    public static function ensure_installation_id() {
        $installation_id = get_option('assessor_installation_id');

        if (empty($installation_id)) {
            $installation_id = self::generate_installation_uuid();
            update_option('assessor_installation_id', $installation_id, 'no');
        }

        return $installation_id;
    }

    /**
     * Generate a cryptographically secure 128-character hexadecimal secret.
     *
     * @return string
     * @throws Exception If random_bytes fails.
     */
    public static function generate_secret() {
        return bin2hex(random_bytes(64));
    }

    /**
     * Generate new deployment identity credentials.
     *
     * @return array Array with 'secret' and 'installation_id'.
     * @throws Exception If random_bytes fails.
     */
    public static function generate_new_deployment_identity() {
        $current_id = self::get_installation_id();
        return array(
            'secret'          => self::generate_secret(),
            'installation_id' => self::generate_installation_uuid($current_id)
        );
    }

    /**
     * Get safe status metadata (never returning secrets or secret hints).
     *
     * @return array
     */
    public static function get_status() {
        return array(
            'jwt_configured'  => self::is_jwt_configured(),
            'installation_id' => self::get_installation_id(),
            'initialized_at'  => (string) get_option('assessor_security_initialized_at', '')
        );
    }

    /**
     * Register Tools > Assessor Security in the WordPress admin menu.
     */
    public static function register_admin_menu() {
        add_submenu_page(
            'tools.php',
            __('Assessor Security Setup', 'assessor-api'),
            __('Assessor Security', 'assessor-api'),
            'manage_options',
            'assessor-security',
            array(__CLASS__, 'render_admin_page')
        );
    }

    /**
     * Render the admin notice if security initialization is required.
     */
    public static function render_admin_notice() {
        if (self::is_jwt_configured()) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // Don't show notice if already on the security setup page
        if (isset($_GET['page']) && $_GET['page'] === 'assessor-security') {
            return;
        }

        $setup_url = admin_url('tools.php?page=assessor-security');
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php esc_html_e('Assessor Security:', 'assessor-api'); ?></strong>
                <?php esc_html_e('Assessor security initialization is required.', 'assessor-api'); ?>
                <a href="<?php echo esc_url($setup_url); ?>">
                    <?php esc_html_e('Go to Tools &rarr; Assessor Security', 'assessor-api'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Locate the real WordPress wp-config.php file.
     * Checks ABSPATH and parent directory of ABSPATH.
     *
     * @return string|null Path to existing wp-config.php, or null if not found.
     */
    public static function locate_wp_config() {
        $candidates = array(
            ABSPATH . 'wp-config.php',
            dirname(ABSPATH) . '/wp-config.php'
        );

        foreach ($candidates as $candidate) {
            if (file_exists($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Attempt to safely write or replace JWT_AUTH_SECRET_KEY in wp-config.php.
     *
     * @param string $secret The 128-char hex secret.
     * @param bool   $replace_existing True if replacing an existing definition (rotation).
     * @return bool True if written successfully, false otherwise.
     */
    public static function write_secret_to_wp_config($secret, $replace_existing = false) {
        $config_file = self::locate_wp_config();

        if (!$config_file || !is_writable($config_file)) {
            return false;
        }

        $content = file_get_contents($config_file);
        if ($content === false) {
            return false;
        }

        $definition = "define( 'JWT_AUTH_SECRET_KEY', '" . $secret . "' );";

        $pattern = "/define\s*\(\s*(['\"])JWT_AUTH_SECRET_KEY\\1\s*,\s*(['\"]).*?\\2\s*\)\s*;/";
        $has_existing = preg_match($pattern, $content);

        if ($has_existing) {
            if (!$replace_existing) {
                // Initial setup: already defined, do not overwrite
                return true;
            }
            // Rotation: replace existing definition cleanly
            $new_content = preg_replace($pattern, $definition, $content, 1);
        } else {
            // New definition: insert before "That's all, stop editing!"
            $stop_marker = "/* That's all, stop editing! Happy publishing. */";
            if (strpos($content, $stop_marker) !== false) {
                $new_content = str_replace($stop_marker, $definition . "\n" . $stop_marker, $content);
            } else {
                $new_content = preg_replace('/^<\?php\s*/m', "<?php\n\n" . $definition . "\n", $content, 1);
            }
        }

        if (empty($new_content) || $new_content === $content) {
            return false;
        }

        // Write safely with exclusive file lock
        $result = file_put_contents($config_file, $new_content, LOCK_EX);

        return ($result !== false);
    }

    /**
     * Admin post action handler: assessor_initialize_security
     */
    public static function handle_initialize_security() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'assessor-api'), 403);
        }

        check_admin_referer('assessor_initialize_security', 'assessor_security_nonce');

        $redirect_base = admin_url('tools.php?page=assessor-security');

        // Check if already configured
        if (self::is_jwt_configured()) {
            wp_safe_redirect(add_query_arg('status', 'already_configured', $redirect_base));
            exit;
        }

        try {
            $secret = self::generate_secret();
        } catch (Exception $e) {
            wp_safe_redirect(add_query_arg('error', 'generation_failed', $redirect_base));
            exit;
        }

        $written = self::write_secret_to_wp_config($secret, false);

        if ($written) {
            update_option('assessor_security_initialized_at', current_time('mysql'), 'no');

            // Log non-sensitive audit message without exposing the secret
            error_log('Assessor Security: JWT secret configured successfully.');

            // Clear any temporary bootstrap transient for this user if one existed
            $user_id = get_current_user_id();
            delete_transient(self::TRANSIENT_PREFIX . $user_id);

            wp_safe_redirect(add_query_arg('success', 'jwt_initialized', $redirect_base));
            exit;
        }

        // File is not writable: set short-lived user-specific transient for manual setup (10 minutes)
        $user_id = get_current_user_id();
        set_transient(self::TRANSIENT_PREFIX . $user_id, $secret, 10 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg('status', 'manual_required', $redirect_base));
        exit;
    }

    /**
     * Admin post action handler: assessor_reinitialize_deployment
     */
    public static function handle_reinitialize() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'assessor-api'), 403);
        }

        check_admin_referer('assessor_reinitialize_deployment', 'assessor_reinitialize_nonce');

        $redirect_base = admin_url('tools.php?page=assessor-security');

        $confirmation_input = isset($_POST['confirmation_text']) ? trim(sanitize_text_field($_POST['confirmation_text'])) : '';
        if ($confirmation_input !== 'REINITIALIZE') {
            wp_safe_redirect(add_query_arg('error', 'reinit_confirmation_failed', $redirect_base));
            exit;
        }

        try {
            $identity = self::generate_new_deployment_identity();
        } catch (Exception $e) {
            wp_safe_redirect(add_query_arg('error', 'generation_failed', $redirect_base));
            exit;
        }

        $new_secret = $identity['secret'];
        $new_installation_id = $identity['installation_id'];

        $written = self::write_secret_to_wp_config($new_secret, true);

        if ($written) {
            // Only update installation ID after successful write to wp-config.php
            update_option('assessor_installation_id', $new_installation_id, 'no');
            update_option('assessor_security_initialized_at', current_time('mysql'), 'no');

            // Audit log without secret exposure
            if (class_exists('Assessor_Audit')) {
                $audit = new Assessor_Audit();
                $audit->log_activity(
                    get_current_user_id(),
                    'reinitialize_deployment',
                    'assessor_security',
                    $new_installation_id,
                    null,
                    array('event' => 'Deployment identity reinitialized')
                );
            }
            error_log('Assessor Security: Deployment identity reinitialized successfully.');

            $user_id = get_current_user_id();
            delete_transient(self::REINIT_TRANSIENT_PREFIX . $user_id);

            wp_safe_redirect(add_query_arg('success', 'reinitialized', $redirect_base));
            exit;
        }

        // wp-config.php was not writable: store pending data in 10-minute transient
        $user_id = get_current_user_id();
        set_transient(
            self::REINIT_TRANSIENT_PREFIX . $user_id,
            array(
                'secret'          => $new_secret,
                'installation_id' => $new_installation_id,
                'timestamp'       => time()
            ),
            10 * MINUTE_IN_SECONDS
        );

        wp_safe_redirect(add_query_arg('status', 'reinit_manual_required', $redirect_base));
        exit;
    }

    /**
     * Admin post action handler: assessor_verify_reinitialization
     */
    public static function handle_verify_reinitialization() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'assessor-api'), 403);
        }

        check_admin_referer('assessor_verify_reinitialization', 'assessor_verify_reinit_nonce');

        $redirect_base = admin_url('tools.php?page=assessor-security');
        $user_id = get_current_user_id();
        $pending = get_transient(self::REINIT_TRANSIENT_PREFIX . $user_id);

        if (!$pending || empty($pending['secret']) || empty($pending['installation_id'])) {
            wp_safe_redirect(add_query_arg('error', 'reinit_session_expired', $redirect_base));
            exit;
        }

        // Verify constant matches expected secret exactly
        if (!defined('JWT_AUTH_SECRET_KEY') || !hash_equals($pending['secret'], JWT_AUTH_SECRET_KEY)) {
            wp_safe_redirect(add_query_arg('error', 'reinit_verification_failed', $redirect_base));
            exit;
        }

        // Successfully verified: update options now
        update_option('assessor_installation_id', $pending['installation_id'], 'no');
        update_option('assessor_security_initialized_at', current_time('mysql'), 'no');

        // Audit log without secret exposure
        if (class_exists('Assessor_Audit')) {
            $audit = new Assessor_Audit();
            $audit->log_activity(
                $user_id,
                'reinitialize_deployment_verified',
                'assessor_security',
                $pending['installation_id'],
                null,
                array('event' => 'Deployment identity reinitialized and manually verified')
            );
        }
        error_log('Assessor Security: Deployment identity manual reinitialization verified.');

        delete_transient(self::REINIT_TRANSIENT_PREFIX . $user_id);

        wp_safe_redirect(add_query_arg('success', 'reinitialized', $redirect_base));
        exit;
    }

    /**
     * Admin post action handler: assessor_cancel_reinitialization
     */
    public static function handle_cancel_reinitialization() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'assessor-api'), 403);
        }

        check_admin_referer('assessor_cancel_reinitialization', 'assessor_cancel_reinit_nonce');

        $user_id = get_current_user_id();
        delete_transient(self::REINIT_TRANSIENT_PREFIX . $user_id);

        wp_safe_redirect(admin_url('tools.php?page=assessor-security'));
        exit;
    }

    /**
     * Render the native WordPress Admin UI page.
     */
    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'assessor-api'));
        }

        $installation_id = self::ensure_installation_id();
        $is_configured = self::is_jwt_configured();
        $initialized_at = get_option('assessor_security_initialized_at', '');

        $user_id = get_current_user_id();
        $manual_secret = get_transient(self::TRANSIENT_PREFIX . $user_id);
        $pending_reinit = get_transient(self::REINIT_TRANSIENT_PREFIX . $user_id);

        // If configured now and a manual setup transient was stored earlier, clean it up
        if ($is_configured && $manual_secret) {
            delete_transient(self::TRANSIENT_PREFIX . $user_id);
            $manual_secret = false;
        }

        $success_param = isset($_GET['success']) ? sanitize_text_field($_GET['success']) : '';
        $status_param  = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $error_param   = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';
        $action_param  = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Assessor Security Setup', 'assessor-api'); ?></h1>

            <?php if ($success_param === 'jwt_initialized') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Security initialization completed successfully! JWT signing secret has been installed in wp-config.php.', 'assessor-api'); ?></p>
                </div>
            <?php elseif ($success_param === 'reinitialized') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <strong><?php esc_html_e('Deployment Reinitialized ✓', 'assessor-api'); ?></strong><br>
                        <?php esc_html_e('Existing assessor records were preserved.', 'assessor-api'); ?><br>
                        <em><?php esc_html_e('Existing JWT sessions from the previous deployment are no longer valid and users must sign in again.', 'assessor-api'); ?></em>
                    </p>
                </div>
            <?php elseif ($status_param === 'already_configured') : ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php esc_html_e('JWT security is already configured. Existing configuration was preserved.', 'assessor-api'); ?></p>
                </div>
            <?php elseif ($status_param === 'manual_required') : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('Automatic configuration could not modify wp-config.php. Please add the configuration block manually below.', 'assessor-api'); ?></p>
                </div>
            <?php elseif ($status_param === 'reinit_manual_required') : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('Automatic configuration could not modify wp-config.php for deployment reinitialization. Manual configuration is required below.', 'assessor-api'); ?></p>
                </div>
            <?php elseif ($error_param === 'reinit_confirmation_failed') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Reinitialization cancelled: You must type REINITIALIZE exactly as instructed to proceed.', 'assessor-api'); ?></p>
                </div>
            <?php elseif ($error_param === 'reinit_session_expired') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Reinitialization session has expired. Please initiate reinitialization again.', 'assessor-api'); ?></p>
                </div>
            <?php elseif ($error_param === 'reinit_verification_failed') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Verification failed: The JWT_AUTH_SECRET_KEY in wp-config.php does not match the pending key. Please verify your wp-config.php file.', 'assessor-api'); ?></p>
                </div>
            <?php elseif ($error_param === 'generation_failed') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Failed to generate cryptographically secure random bytes. Please verify PHP environment.', 'assessor-api'); ?></p>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Installation Security', 'assessor-api'); ?></h2>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Installation ID', 'assessor-api'); ?></th>
                            <td>
                                <code><?php echo esc_html($installation_id); ?></code>
                                <p class="description">
                                    <?php esc_html_e('Unique non-secret identifier for this deployment.', 'assessor-api'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('JWT Authentication Secret', 'assessor-api'); ?></th>
                            <td>
                                <?php if ($is_configured) : ?>
                                    <span style="color: #46b450; font-weight: bold;">
                                        <?php esc_html_e('Configured ✓', 'assessor-api'); ?>
                                    </span>
                                    <?php if (!empty($initialized_at)) : ?>
                                        <p class="description">
                                            <?php printf(esc_html__('Initialized on: %s', 'assessor-api'), esc_html($initialized_at)); ?>
                                        </p>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span style="color: #dc3232; font-weight: bold;">
                                        <?php esc_html_e('Not Configured', 'assessor-api'); ?>
                                    </span>
                                    <p class="description" style="color: #dc3232;">
                                        <?php esc_html_e('This installation does not yet have a deployment-specific JWT signing key.', 'assessor-api'); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php if (!$is_configured) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 20px;">
                        <?php wp_nonce_field('assessor_initialize_security', 'assessor_security_nonce'); ?>
                        <input type="hidden" name="action" value="assessor_initialize_security">
                        <?php submit_button(__('Generate & Install Security Key', 'assessor-api'), 'primary', 'submit', false); ?>
                    </form>

                    <?php if (!empty($manual_secret)) : ?>
                        <div style="margin-top: 25px; padding: 15px; background: #fcfcfc; border-left: 4px solid #dba617;">
                            <h3><?php esc_html_e('Manual Configuration Required', 'assessor-api'); ?></h3>
                            <p><?php esc_html_e('Automatic configuration could not modify wp-config.php. Add the following line to your wp-config.php file before the "stop editing" line:', 'assessor-api'); ?></p>
                            <pre style="background: #23282d; color: #fff; padding: 15px; border-radius: 4px; overflow-x: auto; user-select: all;"><code>define( 'JWT_AUTH_SECRET_KEY', '<?php echo esc_html($manual_secret); ?>' );</code></pre>
                            <p class="description">
                                <?php esc_html_e('Note: This temporary key is kept in memory for 10 minutes for your setup session only. Refresh this page after editing wp-config.php to verify.', 'assessor-api'); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($is_configured) : ?>
                <?php if (!empty($pending_reinit) && is_array($pending_reinit)) : ?>
                    <!-- Pending Manual Reinitialization Verification Block -->
                    <div class="card" style="max-width: 800px; margin-top: 20px; border-left: 4px solid #dba617;">
                        <h2><?php esc_html_e('Manual Reinitialization In Progress', 'assessor-api'); ?></h2>
                        <p><?php esc_html_e('Automatic configuration could not modify wp-config.php. Follow these steps to complete reinitialization:', 'assessor-api'); ?></p>
                        
                        <ol>
                            <li>
                                <?php esc_html_e('Locate the existing JWT_AUTH_SECRET_KEY line in your wp-config.php file and replace it with:', 'assessor-api'); ?>
                                <pre style="background: #23282d; color: #fff; padding: 12px; border-radius: 4px; margin: 10px 0; overflow-x: auto; user-select: all;"><code>define( 'JWT_AUTH_SECRET_KEY', '<?php echo esc_html($pending_reinit['secret']); ?>' );</code></pre>
                            </li>
                            <li>
                                <?php esc_html_e('Proposed new Installation ID to be applied upon verification:', 'assessor-api'); ?><br>
                                <code><?php echo esc_html($pending_reinit['installation_id']); ?></code>
                            </li>
                            <li>
                                <?php esc_html_e('Save wp-config.php, then click "Verify New Deployment Configuration" below.', 'assessor-api'); ?>
                            </li>
                        </ol>

                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('assessor_verify_reinitialization', 'assessor_verify_reinit_nonce'); ?>
                                <input type="hidden" name="action" value="assessor_verify_reinitialization">
                                <?php submit_button(__('Verify New Deployment Configuration', 'assessor-api'), 'primary', 'submit', false); ?>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('assessor_cancel_reinitialization', 'assessor_cancel_reinit_nonce'); ?>
                                <input type="hidden" name="action" value="assessor_cancel_reinitialization">
                                <?php submit_button(__('Cancel Reinitialization', 'assessor-api'), 'secondary', 'submit', false); ?>
                            </form>
                        </div>
                    </div>
                <?php elseif ($action_param === 'confirm_reinitialize') : ?>
                    <!-- Step 2: Two-Stage Confirmation UI -->
                    <div class="card" style="max-width: 800px; margin-top: 20px; border-left: 4px solid #d63638; background: #fff5f5;">
                        <h2 style="color: #d63638;"><?php esc_html_e('Confirm Deployment Reinitialization', 'assessor-api'); ?></h2>
                        <p><strong><?php esc_html_e('Warning: This action will:', 'assessor-api'); ?></strong></p>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li><?php esc_html_e('Generate a new installation ID.', 'assessor-api'); ?></li>
                            <li><?php esc_html_e('Generate a new JWT signing key.', 'assessor-api'); ?></li>
                            <li><?php esc_html_e('Invalidate existing JWT sessions/tokens for this deployment.', 'assessor-api'); ?></li>
                            <li><strong><?php esc_html_e('Keep assessor records and database data intact.', 'assessor-api'); ?></strong></li>
                            <li><?php esc_html_e('Make this installation independent from the deployment it was cloned from.', 'assessor-api'); ?></li>
                        </ul>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 20px;">
                            <?php wp_nonce_field('assessor_reinitialize_deployment', 'assessor_reinitialize_nonce'); ?>
                            <input type="hidden" name="action" value="assessor_reinitialize_deployment">

                            <p>
                                <label for="confirmation_text">
                                    <strong><?php esc_html_e('Confirmation: Type REINITIALIZE to continue:', 'assessor-api'); ?></strong>
                                </label><br>
                                <input type="text" id="confirmation_text" name="confirmation_text" autocomplete="off" required style="width: 250px; margin-top: 5px; font-family: monospace;">
                            </p>

                            <div style="display: flex; gap: 10px; margin-top: 15px;">
                                <a href="<?php echo esc_url(admin_url('tools.php?page=assessor-security')); ?>" class="button button-secondary">
                                    <?php esc_html_e('Cancel', 'assessor-api'); ?>
                                </a>
                                <?php submit_button(__('Reinitialize Deployment', 'assessor-api'), 'button-link-delete button-primary', 'submit', false, array('style' => 'background: #d63638; border-color: #d63638; color: #fff;')); ?>
                            </div>
                        </form>
                    </div>
                <?php else : ?>
                    <!-- Step 1: Normal Configured Deployment Section -->
                    <div class="card" style="max-width: 800px; margin-top: 20px;">
                        <h2><?php esc_html_e('New Deployment', 'assessor-api'); ?></h2>
                        <p><?php esc_html_e('This option is used when this WordPress installation was cloned from another Assessor deployment.', 'assessor-api'); ?></p>
                        <p class="description" style="margin-bottom: 15px;">
                            <?php esc_html_e('Reinitializing generates a new installation identity and new JWT signing key, isolating this deployment while preserving all assessor records and database content.', 'assessor-api'); ?>
                        </p>
                        <a href="<?php echo esc_url(admin_url('tools.php?page=assessor-security&action=confirm_reinitialize')); ?>" class="button button-secondary">
                            <?php esc_html_e('Reinitialize as New Deployment', 'assessor-api'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
