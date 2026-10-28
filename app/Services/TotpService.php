<?php

namespace App\Services;

class TotpService
{
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $email, string $secret, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$email);

        return 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public function verify(string $secret, string $code, int $window = 1): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $key = $this->base32Decode($secret);
        $counter = intdiv(now()->timestamp, 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $value = $counter + $offset;
            $high = intdiv($value, 4294967296);
            $low = $value % 4294967296;
            $hash = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
            $index = ord($hash[19]) & 0x0F;
            $binary = ((ord($hash[$index]) & 0x7F) << 24)
                | ((ord($hash[$index + 1]) & 0xFF) << 16)
                | ((ord($hash[$index + 2]) & 0xFF) << 8)
                | (ord($hash[$index + 3]) & 0xFF);
            $expected = str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);

            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) === 5) {
                $encoded .= $alphabet[bindec($chunk)];
            }
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper(rtrim($encoded, '='))) as $character) {
            $position = strpos($alphabet, $character);
            if ($position === false) {
                return '';
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
