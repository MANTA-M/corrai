<?php

namespace Corrai\Utils;

use \Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Email functions class.
 */
class Emailer
{
    public function __construct(
        private string $recipient_adress,
        private string $subject,
        private string $body,
    ) {}

    public function send(): void {
        $mailer_type = $_ENV['EMAILER'] ?? 'log';
        $sender = $_ENV['EMAIL_SENDER'] ?? '<Corrai>no-reply@corrai.local';
        if ($mailer_type === 'log') {
            $logFile = fopen(Utils::getLogDir() . 'mail.log', 'a');
            if ($logFile === false) {
                throw new Exception('Cannot open mail log file');
            }

            fprintf($logFile, "===== ON %s =======\n", (new \DateTime())->format('Y-m-d H:i:s'));
            fprintf($logFile, "From: %s\n", $sender);

            fprintf($logFile, "To: %s\n", $this->recipient_adress);

            fprintf($logFile, "Subject: %s\n", $this->subject ?? '');
            fwrite($logFile, $this->body . "\n");
            fwrite($logFile, "\n");
            fclose($logFile);
        } elseif ($mailer_type === 'smtp') {
            $this->sendSmtp();
        } else {
            throw new Exception("Unknown EMAILER type: $mailer_type");
        }
    }

    private function sendSmtp(): void {
        $host = $_ENV['MAIL_SMTP_HOST'] ?? '';
        $user = $_ENV['MAIL_SMTP_USER'] ?? '';
        $password = $_ENV['MAIL_SMTP_PASSWORD'] ?? '';
        $from = $_ENV['MAIL_FROM'] ?? $user;
        $port = (int) ($_ENV['MAIL_SMTP_PORT'] ?? 587);
        $auth = filter_var($_ENV['MAIL_SMTP_AUTH'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $secure = strtolower($_ENV['MAIL_SMTP_SECURE'] ?? 'tls');

        if ($host === '' || $user === '' || $password === '' || $from === '') {
            throw new Exception('MAIL_SMTP_HOST, MAIL_SMTP_USER, MAIL_SMTP_PASSWORD and MAIL_FROM must be set');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = $auth;
            $mail->Username = $user;
            $mail->Password = $password;
            $mail->Port = $port;
            $mail->CharSet = 'UTF-8';

            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
            }

            if ($host === 'ssl0.ovh.net' && $port === 587 && $secure === 'tls') {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }

            $mail->setFrom($from);
            $mail->addAddress($this->recipient_adress);
            $mail->Subject = $this->subject;
            $mail->Body = $this->body;
            $mail->isHTML(false);

            $mail->send();
        } catch (PHPMailerException $e) {
            throw new Exception('Failed to send email: ' . $mail->ErrorInfo);
        }
    }
}
?>
