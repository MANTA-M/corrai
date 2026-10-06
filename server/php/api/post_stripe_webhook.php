<?php

use Corrai\Model\Assessment;
use Corrai\Payment\CorrectionCheckout;
use Corrai\Payment\StripeClient;
use Corrai\Utils\Http\Request;

try {
    $payload = file_get_contents('php://input');
    $event = StripeClient::fromEnv()->constructWebhookEvent(
        is_string($payload) ? $payload : '',
        StripeClient::signatureHeader()
    );

    if ($event->type === 'checkout.session.completed') {
        $session = $event->data->object;
        $sessionId = (string) ($session->id ?? '');
        $metadata = $session->metadata ?? null;
        $assessmentId = $metadata === null ? '' : (string) ($metadata['assessment_id'] ?? '');
        if ($sessionId !== '' && $assessmentId !== '') {
            $assessment = Assessment::from_hash($assessmentId);
            CorrectionCheckout::fromEnv()->fulfill($assessment, $sessionId);
        }
    }

    Request::add_output("received", true);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
