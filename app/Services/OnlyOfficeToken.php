<?php

namespace App\Services;

use Illuminate\Support\Str;

/** ONLYOFFICE uses a shared secret and HS256 for editor and callback tokens. */
class OnlyOfficeToken
{
    public function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', "$header.$body", $this->secret(), true);

        return "$header.$body.".$this->base64UrlEncode($signature);
    }

    public function decode(string $token): array
    {
        abort_if(strlen($token) > 65536, 403);
        $parts = explode('.', $token);
        abort_unless(count($parts) === 3, 403, 'Invalid ONLYOFFICE token.');
        [$header, $body, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', "$header.$body", $this->secret(), true));
        abort_unless(hash_equals($expected, $signature), 403, 'Invalid ONLYOFFICE signature.');

        $header = json_decode(base64_decode(strtr($header, '-_', '+/'), true) ?: '', true);
        $payload = json_decode(base64_decode(strtr($body, '-_', '+/'), true) ?: '', true);
        abort_unless(is_array($header) && ($header['alg'] ?? null) === 'HS256' && is_array($payload), 403);
        abort_if(isset($payload['exp']) && (! is_numeric($payload['exp']) || $payload['exp'] <= time()), 403);
        abort_if(isset($payload['nbf']) && (! is_numeric($payload['nbf']) || $payload['nbf'] > time()), 403);

        return $payload;
    }

    private function secret(): string
    {
        $secret = (string) config('onlyoffice.jwt_secret');
        abort_if(Str::length($secret) < 32, 503, 'ONLYOFFICE chưa được cấu hình.');

        return $secret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
