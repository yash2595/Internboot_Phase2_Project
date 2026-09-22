<?php
// Path: src/core/Mailer.php

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_otp_email(string $toEmail, string $toName, string $otp): bool {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME'];
        $mail->Password   = $_ENV['MAIL_PASSWORD'];
        $mail->SMTPSecure = 'tls';
        $mail->Port       = (int) $_ENV['MAIL_PORT'];

        $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
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
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>