<?php

declare(strict_types=1);

namespace App\Licensing;

final class EliteLicenser
{
    public static function encrypt(string $plainText, string $password): string
    {
        $plainText = rand(10, 99) . $plainText . rand(10, 99);
        $method = 'aes-256-cbc';
        $key = substr(hash('sha256', $password, true), 0, 32);
        $iv = substr(strtoupper(md5($password)), 0, 16);
        $ct = openssl_encrypt($plainText, $method, $key, OPENSSL_RAW_DATA, $iv);
        if ($ct === false) {
            return '';
        }
        return base64_encode($ct);
    }

    public static function decrypt(string $cipherTextB64, string $password): string
    {
        $method = 'aes-256-cbc';
        $key = substr(hash('sha256', $password, true), 0, 32);
        $iv = substr(strtoupper(md5($password)), 0, 16);
        $raw = base64_decode($cipherTextB64, true);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $pt = openssl_decrypt($raw, $method, $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($pt) || $pt === '') {
            return '';
        }
        if (strlen($pt) < 4) {
            return '';
        }
        return substr($pt, 2, -2);
    }

    private static function domainFromRequest(): string
    {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = (string) $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
        }
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        $basePath = str_replace(basename($script), '', $script);
        return rtrim($proto . '://' . $host . $basePath, '/') . '/';
    }

    private static function request(string $serverHost, string $relativeUrl, array $payload, string $key, ?string &$curlError = null): ?object
    {
        $curlError = null;
        $dataJson = json_encode($payload);
        if (!is_string($dataJson)) {
            return null;
        }
        $finalData = self::encrypt($dataJson, $key);
        if ($finalData === '') {
            return null;
        }

        $url = rtrim($serverHost, '/') . '/' . ltrim($relativeUrl, '/');
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $finalData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/plain',
                'cache-control: no-cache',
            ],
        ]);

        $serverResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($serverResponse) || $serverResponse === '') {
            return null;
        }

        $decodedJson = self::decrypt($serverResponse, $key);
        if ($decodedJson === '') {
            $obj = json_decode($serverResponse);
            if (is_object($obj)) {
                return $obj;
            }
            return null;
        }

        $obj = json_decode($decodedJson);
        if (!is_object($obj)) {
            return null;
        }
        return $obj;
    }

    public static function checkLicense(
        string $serverHost,
        string $key,
        string $productId,
        string $productBase,
        string $licenseKey,
        string $appVersion,
        ?string $adminEmail = null,
        ?string $domain = null
    ): array {
        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => 'Missing license key'];
        }
        $domain = ($domain !== null && trim($domain) !== '') ? trim($domain) : self::domainFromRequest();

        $payload = [
            'license_key' => $licenseKey,
            'email' => $adminEmail ?? '',
            'domain' => $domain,
            'app_version' => $appVersion,
            'product_id' => $productId,
            'product_base' => $productBase,
        ];

        $err = null;
        $resp = self::request($serverHost, 'product/active/' . rawurlencode($productId), $payload, $key, $err);
        if (!is_object($resp)) {
            return ['ok' => false, 'error' => ($err !== null && $err !== '') ? $err : 'Empty response'];
        }

        if (!empty($resp->code)) {
            $msg = !empty($resp->message) ? (string) $resp->message : 'Request failed';
            return ['ok' => false, 'error' => $msg];
        }

        $status = !empty($resp->status);
        $msg = !empty($resp->msg) ? (string) $resp->msg : '';
        if (!$status) {
            return ['ok' => false, 'error' => $msg !== '' ? $msg : 'Invalid license'];
        }

        if (empty($resp->data) || !is_string($resp->data)) {
            return ['ok' => false, 'error' => 'Invalid data'];
        }

        $serial = self::decrypt($resp->data, $domain);
        if ($serial === '') {
            return ['ok' => false, 'error' => 'Could not decode license data'];
        }

        $licenseObj = @unserialize($serial);
        if (!is_object($licenseObj)) {
            return ['ok' => false, 'error' => 'Could not parse license'];
        }

        $isValid = !empty($licenseObj->is_valid);
        if (!$isValid) {
            return ['ok' => false, 'error' => $msg !== '' ? $msg : 'Invalid license'];
        }

        $requestDuration = 0;
        if (property_exists($licenseObj, 'request_duration')) {
            $requestDuration = (int) ($licenseObj->request_duration ?? 0);
        }

        return [
            'ok' => true,
            'is_valid' => true,
            'license_title' => (string) ($licenseObj->license_title ?? ''),
            'expire_date' => (string) ($licenseObj->expire_date ?? ''),
            'support_end' => (string) ($licenseObj->support_end ?? ''),
            'request_duration_hours' => $requestDuration,
            'msg' => $msg,
        ];
    }

    public static function deactivate(
        string $serverHost,
        string $key,
        string $productId,
        string $productBase,
        string $licenseKey,
        string $appVersion,
        ?string $adminEmail = null,
        ?string $domain = null
    ): array {
        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => 'Missing license key'];
        }
        $domain = ($domain !== null && trim($domain) !== '') ? trim($domain) : self::domainFromRequest();

        $payload = [
            'license_key' => $licenseKey,
            'email' => $adminEmail ?? '',
            'domain' => $domain,
            'app_version' => $appVersion,
            'product_id' => $productId,
            'product_base' => $productBase,
        ];

        $err = null;
        $resp = self::request($serverHost, 'product/deactive/' . rawurlencode($productId), $payload, $key, $err);
        if (!is_object($resp)) {
            return ['ok' => false, 'error' => ($err !== null && $err !== '') ? $err : 'Empty response'];
        }
        if (!empty($resp->code)) {
            $msg = !empty($resp->message) ? (string) $resp->message : 'Request failed';
            return ['ok' => false, 'error' => $msg];
        }

        if (!empty($resp->status)) {
            return ['ok' => true, 'msg' => (string) ($resp->msg ?? '')];
        }

        return ['ok' => false, 'error' => (string) ($resp->msg ?? 'Deactivation failed')];
    }
}
