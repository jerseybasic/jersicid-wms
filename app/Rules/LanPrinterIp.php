<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * IP printer harus berupa IPv4 di jaringan lokal (LAN) gudang,
 * supaya fitur printer tidak bisa dipakai menghubungi server lain.
 */
class LanPrinterIp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $fail('IP printer harus berupa alamat IPv4 yang valid.');
            return;
        }

        // Private range: 10/8, 172.16/12, 192.168/16. Loopback & link-local ditolak.
        $isPrivate = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
        $isBlocked = str_starts_with($value, '127.') || str_starts_with($value, '0.') || str_starts_with($value, '169.254.');

        if (!$isPrivate || $isBlocked) {
            $fail('IP printer harus berada di jaringan lokal gudang (mis. 192.168.x.x).');
        }
    }
}
