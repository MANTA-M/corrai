<?php

namespace Corrai;

use \Exception;

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
        if($mailer_type === 'log') {
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
        }
    }
}
?>