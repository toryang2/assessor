<?php

class Assessor_Hardware_Lock {
    
    private static $salt = 'AssessorSecretKey2026!'; // Used to generate the Activation Key

    public function __construct() {
        // Nothing for now
    }

    public static function get_raw_machine_uuid() {
        $hardware_id = '';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = @shell_exec('wmic csproduct get uuid 2>nul');
            if ($output) {
                $lines = explode("\n", trim($output));
                if (isset($lines[1])) {
                    $hardware_id = trim($lines[1]);
                }
            }
        } else {
            // Linux fallback
            if (file_exists('/sys/class/dmi/id/product_uuid')) {
                $hardware_id = trim(file_get_contents('/sys/class/dmi/id/product_uuid'));
            } elseif (file_exists('/etc/machine-id')) {
                $hardware_id = trim(file_get_contents('/etc/machine-id'));
            }
        }
        
        // If we still can't get it, use a generated installation ID
        if (empty($hardware_id)) {
            $hardware_id = get_option('assessor_fallback_hw_id');
            if (!$hardware_id) {
                if (function_exists('wp_generate_uuid4')) {
                    $hardware_id = wp_generate_uuid4();
                } else {
                    $hardware_id = md5(uniqid(rand(), true));
                }
                update_option('assessor_fallback_hw_id', $hardware_id);
            }
        }
        return $hardware_id;
    }

    public static function get_hardware_id() {
        $raw = self::get_raw_machine_uuid();
        // Return a 16-character user-friendly string
        return strtoupper(substr(hash('sha256', $raw . 'AssessorHWID'), 0, 16));
    }

    public static function get_expected_activation_key() {
        $hardware_id = self::get_hardware_id();
        return strtoupper(substr(hash('sha256', $hardware_id . self::$salt), 0, 16));
    }

    public static function is_unlocked() {
        // If not a local build, bypass the hardware lock check
        if (!defined('ASSESSOR_IS_LOCAL_BUILD') || !ASSESSOR_IS_LOCAL_BUILD) {
            return true;
        }

        $saved_key = get_option('assessor_hardware_activation_key');
        return $saved_key === self::get_expected_activation_key();
    }

    public function get_status($request) {
        return rest_ensure_response(array(
            'locked' => !self::is_unlocked(),
            'hardware_id' => self::get_hardware_id()
        ));
    }

    public function activate($request) {
        $params = $request->get_json_params();
        $key = isset($params['activation_key']) ? trim($params['activation_key']) : '';
        
        if ($key === self::get_expected_activation_key()) {
            update_option('assessor_hardware_activation_key', $key);
            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Application unlocked successfully.'
            ));
        }
        
        return new WP_Error('invalid_key', 'Invalid Activation Key.', array('status' => 400));
    }
}
