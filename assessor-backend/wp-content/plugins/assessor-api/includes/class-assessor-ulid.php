<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assessor ULID Generator
 * 
 * Generates 26-character time-based, lexicographically sortable identifiers (ULIDs).
 */
class Assessor_ULID {
    
    // Crockford's Base32 alphabet for Time (Uppercase)
    private static $encoding32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    
    // Base62 alphabet for Random (Mixed Case)
    private static $encoding62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    
    /**
     * Generate a new ULID
     * @return string 26-character ULID
     */
    public static function generate() {
        $timestamp = (int) (microtime(true) * 1000);
        $time_chars = self::encodeTime($timestamp, 10);
        $random_chars = self::encodeRandom(16);
        return $time_chars . $random_chars;
    }

    /**
     * Generate a prefixed ULID
     * @param string $prefix
     * @return string e.g. USR-CBU-01ARZ3NDEKTSV4RRFFQ69G5FAV
     */
    public static function generate_with_prefix($prefix) {
        if (empty($prefix)) {
            $prefix = 'UNK';
        }
        // Force uppercase, remove non-alphanumeric chars, and limit to 3 letters
        $prefix = strtoupper(trim($prefix));
        $prefix = substr(preg_replace('/[^A-Z0-9]/', '', $prefix), 0, 3);
        
        return 'USR-' . $prefix . '-' . self::generate();
    }

    private static function encodeTime($time, $length) {
        $str = '';
        for ($i = $length - 1; $i >= 0; $i--) {
            $mod = $time % 32;
            $str = self::$encoding32[$mod] . $str;
            $time = ($time - $mod) / 32;
        }
        return $str;
    }

    private static function encodeRandom($length) {
        $str = '';
        try {
            $bytes = random_bytes($length);
            for ($i = 0; $i < $length; $i++) {
                $str .= self::$encoding62[ord($bytes[$i]) % 62];
            }
        } catch (Exception $e) {
            // Fallback if random_bytes fails
            for ($i = 0; $i < $length; $i++) {
                $str .= self::$encoding62[mt_rand(0, 61)];
            }
        }
        return $str;
    }
}
