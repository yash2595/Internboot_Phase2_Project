<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payments_common.php';

$type = ($_GET['type'] ?? 'failure') === 'success' ? 'success' : 'failure';
$response = $_POST;
$txnid = (string)($response['txnid'] ?? '');
$paymentId = isset($response['udf1']) && ctype_digit((string)$response['udf1']) ? (int)$response['udf1'] : 0;
$status = strtolower((string)($response['status'] ?? ''));

$validHash = response_hash_is_valid($response);
if (!$validHash) {
    $status = 'failed';
    $message = 'Payment response validation failed.';
} else {
    $message = $status === 'success' ? 'Payment response received. Verifying transaction with PayU…' : 'Payment was not successful. Enrollment has not been created.';
}

if ($paymentId > 0) {
    $s=$conn->prepare("UPDATE payments SET status=? WHERE id=? AND status='pending'");
    $dbStatus = ($validHash && $status === 'success') ? 'pending' : (($status === 'pending') ? 'pending' : 'failed');
    $s->bind_param('si',$dbStatus,$paymentId); $s->execute(); $s->close();
}

$base = rtrim(envv('APP_BASE_URL'), '/');
if ($base === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $folder = rtrim(str_replace('\\','/',dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $base = $scheme . '://' . $host . ($folder === '/' ? '' : $folder);
}

if ($validHash && $status === 'success') {
    // Use PayU's UDF values from the callback instead of relying on PHP session
    // state, because PayU returns to this URL with a server-to-server POST.
    $candidateId = isset($response['udf2']) && ctype_digit((string)$response['udf2']) ? (int)$response['udf2'] : 0;
    $assessmentId = isset($response['udf3']) && ctype_digit((string)$response['udf3']) ? (int)$response['udf3'] : 0;

    $url = $base . '/index.html?payment=verifying&payment_id=' . urlencode((string)$paymentId)
        . '&candidate_id=' . urlencode((string)$candidateId)
        . '&assessment_id=' . urlencode((string)$assessmentId);
} else {
    $url = $base . '/index.html?payment=' . urlencode($status ?: 'failed') . '&payment_id=' . urlencode((string)$paymentId);
}
unset($_SESSION['m4_payu_payment']);
header('Location: ' . $url);
exit;
