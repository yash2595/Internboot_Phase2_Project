<?php
declare(strict_types=1);

function envv(string $key, string $default = ''): string
{
    global $env;
    return (string)($env[$key] ?? $default);
}

function payu_test_url(): string
{
    return envv('PAYU_PAYMENT_URL', 'https://test.payu.in/_payment');
}

function payu_postservice_url(): string
{
    return envv(
        'PAYU_POSTSERVICE_URL',
        'https://test.payu.in/merchant/postservice.php?form=2'
    );
}

function payu_key(): string
{
    return envv('PAYU_TEST_KEY');
}

function payu_salt(): string
{
    return envv('PAYU_TEST_SALT');
}

function make_txnid(): string
{
    return 'IB' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
}

function request_hash(array $p): string
{
    $key = $p['key'] ?? payu_key();
    $txnid = $p['txnid'] ?? '';
    $amount = $p['amount'] ?? '';
    $productinfo = $p['productinfo'] ?? '';
    $firstname = $p['firstname'] ?? '';
    $email = $p['email'] ?? '';

    $udf1 = $p['udf1'] ?? '';
    $udf2 = $p['udf2'] ?? '';
    $udf3 = $p['udf3'] ?? '';
    $udf4 = $p['udf4'] ?? '';
    $udf5 = $p['udf5'] ?? '';

    $hashString = implode('|', [
        $key,
        $txnid,
        $amount,
        $productinfo,
        $firstname,
        $email,
        $udf1,
        $udf2,
        $udf3,
        $udf4,
        $udf5,
        '',
        '',
        '',
        '',
        '',
        payu_salt()
    ]);

    return strtolower(hash('sha512', $hashString));
}

function response_hash_is_valid(array $r): bool
{
    $received = strtolower((string)($r['hash'] ?? ''));

    if ($received === '') {
        return false;
    }

    $parts = [
        payu_salt(),
        $r['status'] ?? '',
        '',
        '',
        '',
        '',
        '',
        $r['udf5'] ?? '',
        $r['udf4'] ?? '',
        $r['udf3'] ?? '',
        $r['udf2'] ?? '',
        $r['udf1'] ?? '',
        $r['email'] ?? '',
        $r['firstname'] ?? '',
        $r['productinfo'] ?? '',
        $r['amount'] ?? '',
        $r['txnid'] ?? '',
        $r['key'] ?? ''
    ];

    $expected = strtolower(
        hash('sha512', implode('|', $parts))
    );

    return hash_equals($expected, $received);
}

function verify_payment_with_payu(string $txnid): array
{
    $key = payu_key();
    $salt = payu_salt();

    if ($key === '' || $salt === '') {
        throw new RuntimeException(
            'PayU test credentials are missing.'
        );
    }

    $command = 'verify_payment';

    $hash = strtolower(
        hash(
            'sha512',
            implode('|', [
                $key,
                $command,
                $txnid,
                $salt
            ])
        )
    );

    $post = http_build_query([
        'key' => $key,
        'command' => $command,
        'var1' => $txnid,
        'hash' => $hash,
    ]);

    $ch = curl_init(payu_postservice_url());

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);

        throw new RuntimeException(
            'PayU verification request failed: ' . $error
        );
    }

    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'Invalid PayU verification response. HTTP ' . $http
        );
    }

    return $data;
}

function payu_status(array $data): string
{
    $status = strtolower(
        (string)($data['status'] ?? '')
    );

    if (
        isset($data['transaction_details']) &&
        is_array($data['transaction_details'])
    ) {
        $details = array_values(
            $data['transaction_details']
        );

        if (isset($details[0]['status'])) {
            $status = strtolower(
                (string)$details[0]['status']
            );
        } elseif (isset($details[0]['unmappedstatus'])) {
            $status = strtolower(
                (string)$details[0]['unmappedstatus']
            );
        }
    }

    return $status;
}