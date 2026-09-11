<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assessor UUID v7 Generator (RFC 9562)
 *
 * Generates 36-character, time-ordered, cryptographically secure UUID v7 identifiers.
 * Format: xxxxxxxx-xxxx-7xxx-yxxx-xxxxxxxxxxxx
 */
class Assessor_UUID {

    /**
     * Generate a new UUID v7 string.
     *
     * @param int|null $timestampMs Optional Unix timestamp in milliseconds. Defaults to current time.
     * @return string 36-character canonical UUID v7 string
     */
    public static function v7(?int $timestampMs = null): string {
        // High-resolution timestamp in milliseconds
        $timeMs = $timestampMs !== null ? $timestampMs : (int) (microtime(true) * 1000);

        // 48-bit hex representation of milliseconds
        $timeHex = str_pad(dechex($timeMs), 12, '0', STR_PAD_LEFT);

        // 10 cryptographically secure random bytes
        try {
            $randomBytes = random_bytes(10);
        } catch (Exception $e) {
            // Fallback in rare event random_bytes fails
            $randomBytes = openssl_random_pseudo_bytes(10);
        }

        // Substrings for RFC 9562 layout
        $part1 = substr($timeHex, 0, 8);
        $part2 = substr($timeHex, 8, 4);

        // 12 bits of random data prefixed with version '7'
        $randA = bin2hex(substr($randomBytes, 0, 2));
        $part3 = '7' . substr($randA, 1, 3);

        // 14 bits of random data with variant '10xx' (0x8000 - 0xbfff)
        $randB = hexdec(bin2hex(substr($randomBytes, 2, 2)));
        $variant = dechex(($randB & 0x3fff) | 0x8000);
        $part4 = str_pad($variant, 4, '0', STR_PAD_LEFT);

        // 48 bits of random data
        $part5 = bin2hex(substr($randomBytes, 4, 6));

        return sprintf('%s-%s-%s-%s-%s', $part1, $part2, $part3, $part4, $part5);
    }

    /**
     * Validate if a string is a valid UUID format (v4 or v7).
     *
     * @param string $uuid
     * @return bool
     */
    public static function is_valid($uuid): bool {
        if (!is_string($uuid)) {
            return false;
        }
        return (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid);
    }
}
