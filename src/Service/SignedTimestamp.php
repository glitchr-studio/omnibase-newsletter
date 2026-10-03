<?php

namespace Base\Newsletter\Service;

/**
 * The time the form was opened, signed with the kernel's secret: a robot
 * cannot send an old timestamp of its own making to look patient.
 * "1767225600.3f9a..." - the seconds, a dot, an HMAC of them.
 */
final class SignedTimestamp
{
    public static function sign(int $time, string $secret): string
    {
        return $time.'.'.self::mac($time, $secret);
    }

    /** The time it carries, or null when it was not signed with $secret. */
    public static function verify(?string $value, string $secret): ?int
    {
        if (!$value || !preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $value, $parts)) {
            return null;
        }

        return hash_equals(self::mac((int) $parts[1], $secret), $parts[2]) ? (int) $parts[1] : null;
    }

    private static function mac(int $time, string $secret): string
    {
        return hash_hmac('sha256', 'newsletter-opened-at:'.$time, $secret);
    }
}
