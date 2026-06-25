<?php

class Assessor_Messenger_Config {

    const OPTION_KEY = 'assessor_messenger_settings';

    /**
     * Settings from WordPress admin (not fixed .env files).
     */
    public static function load() {
        $saved = get_option(self::OPTION_KEY, array());
        if (!is_array($saved)) {
            $saved = array();
        }

        $saved = wp_parse_args($saved, array(
            'verify_token' => '',
            'page_access_token' => '',
            'app_secret' => '',
        ));

        return array(
            'api_url' => trailingslashit(get_site_url()) . 'wp-json/assessor/v1',
            'verify_token' => (string) $saved['verify_token'],
            'page_access_token' => (string) $saved['page_access_token'],
            'app_secret' => (string) $saved['app_secret'],
        );
    }

    public static function save_from_request() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Not allowed.');
        }

        $verify = isset($_POST['verify_token']) ? sanitize_text_field(wp_unslash($_POST['verify_token'])) : '';
        $page_token = isset($_POST['page_access_token']) ? sanitize_text_field(wp_unslash($_POST['page_access_token'])) : '';
        $app_secret = isset($_POST['app_secret']) ? sanitize_text_field(wp_unslash($_POST['app_secret'])) : '';

        $current = get_option(self::OPTION_KEY, array());
        if (!is_array($current)) {
            $current = array();
        }

        if ($page_token === '' && !empty($current['page_access_token'])) {
            $page_token = $current['page_access_token'];
        }
        if ($app_secret === '' && !empty($current['app_secret'])) {
            $app_secret = $current['app_secret'];
        }

        $data = array(
            'verify_token' => $verify,
            'page_access_token' => $page_token,
            'app_secret' => $app_secret,
        );

        update_option(self::OPTION_KEY, $data, false);

        return true;
    }

    /** Optional: read legacy .env only if WP options are empty (migration). */
    public static function migrate_env_if_empty() {
        $saved = get_option(self::OPTION_KEY, array());
        if (!empty($saved['page_access_token']) || !empty($saved['verify_token'])) {
            return;
        }

        $path = ASSESSOR_MESSENGER_DIR . '.env';
        if (!is_readable($path)) {
            return;
        }

        $map = array();
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            list($k, $v) = explode('=', $line, 2);
            $map[trim($k)] = trim($v, " \t\"'");
        }

        if (empty($map['FB_PAGE_ACCESS_TOKEN']) && empty($map['FB_VERIFY_TOKEN'])) {
            return;
        }

        update_option(self::OPTION_KEY, array(
            'verify_token' => isset($map['FB_VERIFY_TOKEN']) ? $map['FB_VERIFY_TOKEN'] : '',
            'page_access_token' => isset($map['FB_PAGE_ACCESS_TOKEN']) ? $map['FB_PAGE_ACCESS_TOKEN'] : '',
            'app_secret' => isset($map['FB_APP_SECRET']) ? $map['FB_APP_SECRET'] : '',
        ), false);
    }
}
