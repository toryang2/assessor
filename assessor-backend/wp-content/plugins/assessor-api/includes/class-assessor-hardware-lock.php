<?php

class Assessor_Hardware_Lock {
    
    private static $salt = 'AssessorSecretKey2026!'; // Used to generate the Activation Key

    public function __construct() {
        // Nothing for now
    }

    public static function get_raw_machine_uuid() {
        $hardware_id = '';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {

            /*
             * Windows hardware UUID
             *
             * DO NOT rely on WMIC as the primary method.
             * WMIC has been removed from newer Windows 11 releases.
             * WMI itself remains supported, so use PowerShell/CIM.
             */

            // Primary method: PowerShell CIM
            $powershell_command =
                'powershell.exe -NoProfile -NonInteractive -Command ' .
                '"try { ' .
                '$uuid = (Get-CimInstance -ClassName Win32_ComputerSystemProduct -ErrorAction Stop).UUID; ' .
                'if ($uuid) { $uuid } ' .
                '} catch { exit 1 }"';

            $output = @shell_exec($powershell_command);

            if ($output) {
                $candidate = trim($output);

                // Accept only a real-looking UUID value.
                if (
                    $candidate !== '' &&
                    !preg_match('/^(0{8}-0{4}-0{4}-0{4}-0{12})$/i', $candidate) &&
                    !preg_match('/^(to be filled by o\.e\.m\.|unknown|none)$/i', $candidate)
                ) {
                    $hardware_id = $candidate;
                }
            }

            /*
             * Legacy fallback:
             * Keep WMIC support for older Windows installations that still have it.
             */
            if ($hardware_id === '' && function_exists('shell_exec')) {
                $wmic_path = getenv('WINDIR')
                    ? getenv('WINDIR') . '\\System32\\wbem\\wmic.exe'
                    : 'wmic.exe';

                if (file_exists($wmic_path) || @shell_exec('where wmic.exe 2>nul')) {
                    $output = @shell_exec('wmic csproduct get uuid 2>nul');

                    if ($output) {
                        $lines = preg_split('/\r\n|\r|\n/', trim($output));

                        foreach ($lines as $line) {
                            $candidate = trim($line);

                            if (
                                $candidate !== '' &&
                                !preg_match('/^uuid$/i', $candidate) &&
                                !preg_match('/^(0{8}-0{4}-0{4}-0{4}-0{12})$/i', $candidate) &&
                                !preg_match('/^(to be filled by o\.e\.m\.|unknown|none)$/i', $candidate)
                            ) {
                                $hardware_id = $candidate;
                                break;
                            }
                        }
                    }
                }
            }

            /*
             * Last-resort Windows installation identifier.
             *
             * Use the existing saved fallback FIRST so an older installation
             * does not suddenly receive a new ID merely because a hardware
             * query temporarily failed.
             */
            if ($hardware_id === '') {
                $hardware_id = get_option('assessor_fallback_hw_id');
            }

            /*
             * If no historical fallback exists, use Windows MachineGuid.
             *
             * This is much more stable across normal Windows updates than
             * depending on the presence of a command-line utility such as WMIC.
             */
            if ($hardware_id === '') {
                $machine_guid_command =
                    'powershell.exe -NoProfile -NonInteractive -Command ' .
                    '"try { ' .
                    '$guid = (Get-ItemPropertyValue -Path \'\'HKLM:\SOFTWARE\Microsoft\Cryptography\'\' -Name MachineGuid -ErrorAction Stop); ' .
                    'if ($guid) { $guid } ' .
                    '} catch { exit 1 }"';

                $machine_guid = @shell_exec($machine_guid_command);

                if ($machine_guid) {
                    $candidate = trim($machine_guid);

                    if (
                        $candidate !== '' &&
                        !preg_match('/^(0{8}-0{4}-0{4}-0{4}-0{12})$/i', $candidate)
                    ) {
                        $hardware_id = $candidate;
                    }
                }
            }

        } else {
            /*
             * Linux fallback
             */
            if (file_exists('/sys/class/dmi/id/product_uuid')) {
                $hardware_id = trim(file_get_contents('/sys/class/dmi/id/product_uuid'));
            } elseif (file_exists('/etc/machine-id')) {
                $hardware_id = trim(file_get_contents('/etc/machine-id'));
            }
        }

        /*
         * Absolute final fallback.
         *
         * Once generated, persist it so temporary hardware-query failures
         * cannot create a different Hardware ID on every request.
         */
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

        return trim($hardware_id);
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
