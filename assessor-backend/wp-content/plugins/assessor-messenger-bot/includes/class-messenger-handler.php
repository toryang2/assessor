<?php

class Assessor_Messenger_Handler {

    private $config;

    public function __construct($config) {
        $this->config = $config;
    }

    public function process_payload($payload) {
        if (empty($payload['entry']) || !is_array($payload['entry'])) {
            return;
        }

        foreach ($payload['entry'] as $entry) {
            if (empty($entry['messaging']) || !is_array($entry['messaging'])) {
                continue;
            }
            foreach ($entry['messaging'] as $event) {
                $this->process_event($event);
            }
        }
    }

    private function process_event($event) {
        if (!empty($event['message']['is_echo'])) {
            return;
        }

        $sender_id = isset($event['sender']['id']) ? $event['sender']['id'] : '';
        if ($sender_id === '') {
            return;
        }

        $text = '';
        if (!empty($event['message']['text'])) {
            $text = trim($event['message']['text']);
        }

        if ($text === '') {
            return;
        }

        $reply = $this->build_reply($text);
        $this->send_message($sender_id, $reply);
    }

    private function help_text() {
        return "Assessor property search\n\n"
            . "Use one search type at a time:\n"
            . "• search <name> — find by declarant name\n"
            . "• tdn <number> — current declaration for that TD\n\n"
            . "You can also send a full TD alone (e.g. 22-010-0001-00866).\n"
            . "Combined name + TD in one query is disabled.";
    }

    private function looks_like_combined_name_and_td($text) {
        $s = trim((string) $text);
        if ($s === '') {
            return false;
        }

        return preg_match('/[A-Za-z]/', $s)
            && preg_match('/\d+(?:-\d+)+/', $s)
            && !$this->is_whole_td_token_public($s);
    }

    private function build_reply($text) {
        $lower = strtolower($text);

        if ($lower === 'help' || $lower === 'start' || $lower === 'hi' || $lower === 'hello') {
            return $this->help_text();
        }

        if (preg_match('/^search\s+(.+)/i', $text, $m)) {
            $search_text = trim($m[1]);
            if ($this->looks_like_combined_name_and_td($search_text)) {
                return "Combined name + TD is disabled.\n"
                    . "Please use one only:\n"
                    . "• search <name>\n"
                    . "• tdn <tax declaration>";
            }
            return $this->search_properties($search_text);
        }

        if (preg_match('/^tdn\s+(.+)/i', $text, $m)) {
            return $this->get_by_tax_number(trim($m[1]));
        }

        if (strlen($text) >= 2) {
            if ($this->is_whole_td_token_public($text)) {
                return $this->get_by_tax_number(trim($text));
            }
            if ($this->looks_like_combined_name_and_td($text)) {
                return "Combined name + TD is disabled.\n"
                    . "Please use one only:\n"
                    . "• search <name>\n"
                    . "• tdn <tax declaration>";
            }
            return $this->search_properties($text);
        }

        return 'Say help for commands.';
    }

    private function is_whole_td_token_public($text) {
        $s = trim((string) $text);
        if (strlen($s) < 3) {
            return false;
        }
        return (bool) preg_match('/^([A-Za-z]-[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*|\d+(?:-\d+)+)$/u', $s);
    }

    private function search_properties($query) {
        if (!class_exists('Assessor_Public_API')) {
            return 'Assessor API plugin is not active.';
        }

        $public = new Assessor_Public_API();
        $request = new WP_REST_Request('GET', '/assessor/v1/public/properties');
        $trim = trim($query);
        if ($this->is_whole_td_token_public($trim)) {
            $request->set_param('tax_declaration_number', $trim);
        } else {
            $request->set_param('q', $trim);
        }
        $request->set_param('per_page', 5);

        $result = $public->search_properties($request);

        if (is_wp_error($result)) {
            return $result->get_error_message();
        }

        $items = isset($result['properties']) ? $result['properties'] : array();
        if (empty($items)) {
            return 'No properties found for: ' . $query;
        }

        $lines = array('Results for: ' . mb_strtoupper((string) $query), '');
        $count = count($items);
        foreach ($items as $index => $p) {
            $name = trim(
                (isset($p['declarant_last_name']) ? $p['declarant_last_name'] : '') . ', '
                . (isset($p['declarant_first_name']) ? $p['declarant_first_name'] : '')
            );
            $tdn = isset($p['tax_declaration_number']) ? $p['tax_declaration_number'] : '—';
            $loc = isset($p['location']) ? $p['location'] : '';
            $lines[] = "👤 " . ($name !== '' ? $name : '—') . "\n"
                . "🧾 " . $tdn
                . "\n📍 " . ($loc !== '' ? $loc : '—')
                . "\n🆔 " . (isset($p['pin']) ? $p['pin'] : '—');
            if ($index < $count - 1) {
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    private function get_by_tax_number($tax_number) {
        if (!class_exists('Assessor_Public_API')) {
            return 'Assessor API plugin is not active.';
        }

        $tax_number = mb_strtoupper(trim((string) $tax_number));
        $public = new Assessor_Public_API();
        $request = new WP_REST_Request('GET', '/assessor/v1/public/properties');
        $request->set_param('tax_declaration_number', mb_substr($tax_number, 0, 80));
        $request->set_param('per_page', 1);
        $result = $public->search_properties($request);

        if (is_wp_error($result)) {
            return $result->get_error_message();
        }

        $items = isset($result['properties']) ? $result['properties'] : array();
        if (empty($items)) {
            return 'Property not found for TD: ' . $tax_number;
        }
        $p = $items[0];
        $name = trim(
            (isset($p['declarant_last_name']) ? $p['declarant_last_name'] : '') . ', '
            . (isset($p['declarant_first_name']) ? $p['declarant_first_name'] : '')
        );

        $current_tdn = isset($p['tax_declaration_number']) ? mb_strtoupper((string) $p['tax_declaration_number']) : '—';
        return "Property Found: " . $tax_number . "\n\n"
            . "👤 " . ($name !== '' ? $name : '—') . "\n"
            . "🧾 " . $current_tdn . "\n"
            . "📍 " . (isset($p['location']) ? $p['location'] : '—') . "\n"
            . "🆔 " . (isset($p['pin']) ? $p['pin'] : '—');
    }

    private function send_message($recipient_id, $text) {
        if (empty($this->config['page_access_token'])) {
            error_log('Assessor Messenger: FB_PAGE_ACCESS_TOKEN not set.');
            return false;
        }

        $text = mb_substr($text, 0, 2000);

        $url = add_query_arg(
            array('access_token' => $this->config['page_access_token']),
            'https://graph.facebook.com/v21.0/me/messages'
        );

        $response = wp_remote_post($url, array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode(array(
                'recipient' => array('id' => $recipient_id),
                'message' => array('text' => $text),
            )),
        ));

        if (is_wp_error($response)) {
            error_log('Assessor Messenger send error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            error_log('Assessor Messenger send HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
            return false;
        }

        return true;
    }
}
