<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/src/Exception.php';
require_once dirname(__DIR__) . '/src/PHPMailer.php';
require_once dirname(__DIR__) . '/src/SMTP.php';

function sendQuadraEmail(string $recipientEmail, string $recipientName, string $subject, string $body): void
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'clarizematias042@gmail.com';
    $mail->Password = 'uvcf gtcf ztbl rwuh';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom('clarizematias042@gmail.com', 'Quadra Cafe HR');
    $mail->addAddress($recipientEmail, $recipientName);
    $mail->isHTML(false);
    $mail->Subject = $subject;
    $mail->Body = $body;
    $mail->send();
}
