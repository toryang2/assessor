<?php
/**
 * Plugin Name: Assessor Messenger Bot
 * Description: Facebook Messenger webhook for public property search (uses Assessor Public API).
 * Version: 1.0.1
 * Author: Assessor
 * Requires Plugins: assessor-api
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ASSESSOR_MESSENGER_DIR', plugin_dir_path(__FILE__));
define('ASSESSOR_MESSENGER_VERSION', '1.0.1');

require_once ASSESSOR_MESSENGER_DIR . 'includes/class-messenger-config.php';
require_once ASSESSOR_MESSENGER_DIR . 'includes/class-messenger-handler.php';
require_once ASSESSOR_MESSENGER_DIR . 'includes/class-messenger-webhook.php';

function assessor_messenger_bot_init() {
    Assessor_Messenger_Config::migrate_env_if_empty();
    Assessor_Messenger_Webhook::init();
}
add_action('plugins_loaded', 'assessor_messenger_bot_init');

register_activation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

add_action('admin_menu', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    add_options_page(
        'Messenger Bot',
        'Messenger Bot',
        'manage_options',
        'assessor-messenger-bot',
        'assessor_messenger_render_admin_page'
    );
});

add_action('admin_post_assessor_messenger_save', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Forbidden');
    }
    check_admin_referer('assessor_messenger_save');

    Assessor_Messenger_Config::save_from_request();

    wp_safe_redirect(add_query_arg(
        array('page' => 'assessor-messenger-bot', 'updated' => '1'),
        admin_url('options-general.php')
    ));
    exit;
});

function assessor_messenger_render_admin_page() {
    $config = Assessor_Messenger_Config::load();
    $webhook = Assessor_Messenger_Webhook::get_webhook_url();
    $public_search = $config['api_url'] . '/public/properties';
    $updated = isset($_GET['updated']);
    ?>
    <div class="wrap">
        <h1>Facebook Messenger Bot</h1>

        <?php if ($updated) : ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
        <?php endif; ?>

        <h2>Option A — Built-in bot (this plugin)</h2>
        <p>Webhook for Meta Developer Console. Tokens are stored in WordPress (not a fixed <code>.env</code> file).</p>
        <p><strong>Webhook URL:</strong> <code><?php echo esc_html($webhook); ?></code></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('assessor_messenger_save'); ?>
            <input type="hidden" name="action" value="assessor_messenger_save" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="verify_token">Verify token</label></th>
                    <td>
                        <input type="text" class="regular-text" id="verify_token" name="verify_token"
                               value="<?php echo esc_attr($config['verify_token']); ?>"
                               autocomplete="off" />
                        <p class="description">Same value you enter in Meta → Messenger → Webhooks.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="page_access_token">Page access token</label></th>
                    <td>
                        <input type="password" class="large-text" id="page_access_token" name="page_access_token"
                               value="" placeholder="<?php echo $config['page_access_token'] ? '•••••••• (saved — leave blank to keep)' : ''; ?>"
                               autocomplete="new-password" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="app_secret">App secret</label></th>
                    <td>
                        <input type="password" class="regular-text" id="app_secret" name="app_secret"
                               value="" placeholder="<?php echo $config['app_secret'] ? '•••••••• (saved — leave blank to keep)' : ''; ?>"
                               autocomplete="new-password" />
                        <p class="description">Optional; validates webhook POST signatures.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Messenger settings'); ?>
        </form>

        <hr />

        <h2>Option B — Another bot platform (ManyChat, Make, Zapier, your own server)</h2>
        <p>You do <strong>not</strong> need the fields above. You only need an API key from the Assessor app:</p>
        <ol>
            <li>Assessor → <strong>Settings → API Keys</strong> → create a key</li>
            <li>In your bot tool, add an HTTP request:</li>
        </ol>
        <pre style="background:#f6f7f7;padding:12px;overflow:auto;">GET <?php echo esc_html($public_search); ?>?q=SEARCH_TERM
Headers:
  X-API-Key: your_api_key
  X-API-Secret: your_api_secret</pre>
        <p>Site URL is detected automatically: <code><?php echo esc_html($config['api_url']); ?></code></p>
    </div>
    <?php
}
