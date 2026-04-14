<?php

namespace App\Services\FirebaseMigration;

class FirestoreDataNormalizer
{
    /**
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    public static function utf8Recursive(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $k = is_string($key) ? self::sanitizeUtf8String($key) : $key;
            if (is_string($value)) {
                $out[$k] = self::sanitizeUtf8String($value);
            } elseif (is_array($value)) {
                $out[$k] = self::utf8Recursive($value);
            } else {
                $out[$k] = $value;
            }
        }

        return $out;
    }

    public static function sanitizeUtf8String(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        if (preg_match('/\\\\u[0-9a-fA-F]{4}/', $s)) {
            $s = (string) preg_replace_callback(
                '/\\\\u([0-9a-fA-F]{4})/',
                static fn (array $m): string => mb_chr((int) hexdec($m[1]), 'UTF-8'),
                $s
            );
        }
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $s);

        return $fixed !== false ? $fixed : $s;
    }

    public static function truthy(mixed $v): bool
    {
        if ($v === null) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1;
        }
        if (is_string($v)) {
            $t = strtolower(trim($v));

            return in_array($t, ['1', 'true', 'yes'], true);
        }

        return false;
    }

    public static function trimString(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $t = strtolower(trim($email));

        return $t === '' ? null : $t;
    }

    /**
     * @return \Illuminate\Support\Carbon|null
     */
    public static function parseTimestamp(mixed $v)
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $n = (float) $v;
            if ($n > 1e12) {
                $n = $n / 1000;
            }

            try {
                return \Illuminate\Support\Carbon::createFromTimestamp((int) $n);
            } catch (\Throwable) {
                return null;
            }
        }
        if (is_string($v)) {
            try {
                return \Illuminate\Support\Carbon::parse($v);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    public static function jsonOrNull(?array $data, int $maxBytes = 60000): ?array
    {
        if ($data === null || $data === []) {
            return null;
        }
        try {
            $enc = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return null;
        }
        if (strlen($enc) > $maxBytes) {
            return null;
        }

        return $data;
    }

    public static function truncateUtf8(?string $s, int $maxBytes = 60000): ?string
    {
        if ($s === null) {
            return null;
        }
        if ($s === '') {
            return '';
        }
        if (strlen($s) <= $maxBytes) {
            return $s;
        }
        $out = $s;
        while (strlen($out) > $maxBytes && mb_strlen($out, 'UTF-8') > 0) {
            $out = mb_substr($out, 0, -1, 'UTF-8');
        }

        return $out;
    }
}
