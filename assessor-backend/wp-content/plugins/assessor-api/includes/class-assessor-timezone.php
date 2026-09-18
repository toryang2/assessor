<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Application-wide timezone helper.
 *
 * The Assessor application is permanently standardized to
 * Asia/Manila / UTC+08:00.
 */
class Assessor_Timezone {

    public const TIMEZONE = 'Asia/Manila';

    public static function timezone() {
        return new DateTimeZone(self::TIMEZONE);
    }

    public static function now() {
        return new DateTime('now', self::timezone());
    }

    public static function now_mysql() {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function now_iso8601() {
        return self::now()->format('c');
    }

    public static function format($datetime, $format = 'Y-m-d H:i:s') {
        if (empty($datetime)) {
            return null;
        }

        try {
            $date = new DateTime($datetime, self::timezone());
            $date->setTimezone(self::timezone());
            return $date->format($format);
        } catch (Exception $e) {
            return $datetime;
        }
    }

    public static function timezone_name() {
        return self::TIMEZONE;
    }

    public static function offset() {
        return '+08:00';
    }
}
