<?php

class Assessor_Messenger_Webhook {

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function get_webhook_url() {
        return trailingslashit(get_rest_url(null, 'assessor/v1')) . 'messenger/webhook';
    }

    public static function register_routes() {
        register_rest_route('assessor/v1', '/messenger/webhook', array(
            array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'verify'),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'handle'),
                'permission_callback' => '__return_true',
            ),
        ));
    }

    public static function verify($request) {
        $config = Assessor_Messenger_Config::load();
        $mode = $request->get_param('hub_mode');
        $token = $request->get_param('hub_verify_token');
        $challenge = $request->get_param('hub_challenge');

        if ($mode === 'subscribe' && $token === $config['verify_token'] && $config['verify_token'] !== '') {
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo $challenge;
            exit;
        }

        return new WP_Error('forbidden', 'Verification failed.', array('status' => 403));
    }

    public static function handle($request) {
        $config = Assessor_Messenger_Config::load();
        $body = $request->get_body();

        if (!empty($config['app_secret'])) {
            $sig = $request->get_header('x_hub_signature_256');
            if (!$sig && isset($_SERVER['HTTP_X_HUB_SIGNATURE_256'])) {
                $sig = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_HUB_SIGNATURE_256']));
            }
            if ($sig && !self::verify_signature($body, $config['app_secret'], $sig)) {
                return new WP_Error('invalid_signature', 'Invalid signature.', array('status' => 403));
            }
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        $handler = new Assessor_Messenger_Handler($config);
        $handler->process_payload($payload);

        return new WP_REST_Response(array('ok' => true), 200);
    }

    private static function verify_signature($body, $app_secret, $header) {
        $expected = 'sha256=' . hash_hmac('sha256', $body, $app_secret);
        return hash_equals($expected, $header);
    }
}
