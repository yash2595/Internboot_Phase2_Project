<?php
// Path: src/core/Mailer.php

$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!function_exists('env_value')) {
    function env_value(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $_ENV)) return (string)$_ENV[$key];
        $value = getenv($key);
        return $value === false ? $default : (string)$value;
    }
}

function send_otp_email(string $toEmail, string $toName, string $otp): bool {
    $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new RuntimeException('Mail dependencies not installed. Run composer install.');
    }
    require_once $autoloadPath;

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('Mail dependencies not installed. Run composer install.');
    }

    $host = env_value('MAIL_HOST');
    $fromAddress = env_value('MAIL_FROM_ADDRESS');

    if (empty($host) || empty($fromAddress)) {
        throw new RuntimeException('Mail is not configured. Set MAIL_* variables in .env.');
    }

    $port = (int) env_value('MAIL_PORT', '587');
    if ($port < 1 || $port > 65535) {
        $port = 587;
    }

    $username = env_value('MAIL_USERNAME', '');
    $password = env_value('MAIL_PASSWORD', '');
    $encryption = strtolower((string) env_value('MAIL_ENCRYPTION', 'tls'));
    $fromName = env_value('MAIL_FROM_NAME', 'InternBoot');

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = !empty($username);
        if (!empty($username)) {
            $mail->Username   = $username;
            $mail->Password   = $password;
        }

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->Port       = $port;
        $mail->Timeout    = 5;

        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Your InternBoot verification code';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;'>
                <h2 style='color:#2F6FEB;'>InternBoot Email Verification</h2>
                <p>Hi {$toName},</p>
                <p>Your verification code is:</p>
                <div style='font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #1E4FD1; margin: 20px 0;'>{$otp}</div>
                <p>This code expires in 10 minutes. If you didn't request this, you can safely ignore this email.</p>
            </div>
        ";
        $mail->AltBody = "Your InternBoot verification code is: {$otp} (expires in 10 minutes)";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo} | Exception: {$e->getMessage()}");
        return false;
    } catch (Throwable $e) {
        error_log("Mailer Unexpected Error: {$e->getMessage()}");
        return false;
    }
}
?>