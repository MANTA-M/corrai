<?php

namespace Corrai\Payment;

use Corrai\Model\Assessment;
use Corrai\Model\BaseAssessment;
use Corrai\Model\User;
use Corrai\Utils\WSException;
use Stripe\Checkout\Session;

/**
 * Hosted Checkout for one correction batch: 1 € per unclassified copy, minus the teacher discount.
 */
class CorrectionCheckout
{
    public const UNIT_AMOUNT = 100;
    public const MIN_CHARGE = 50;
    public const CURRENCY = 'eur';

    /**
     * Cents per copy after a 0–100 percent discount. A rate of 100 yields 0.
     */
    public static function unitAmount(int $discountRate): int
    {
        return intdiv(self::UNIT_AMOUNT * (100 - $discountRate), 100);
    }

    public function __construct(private StripeClient $stripe)
    {
    }

    public static function fromEnv(): self
    {
        return new self(StripeClient::fromEnv());
    }

    /**
     * @return array{url: ?string, files: ?array}
     */
    public function create(BaseAssessment $assessment): array
    {
        $count = count($assessment->unclassifiedFileIds());
        if ($count < 1) {
            throw new WSException('No copies to correct', 400);
        }
        if ($assessment->id === null || $assessment->id === '') {
            throw new WSException('Assessment id is required', 400);
        }

        $unitAmount = $this->unitAmountFor($assessment);
        if ($unitAmount === 0) {
            $files = $assessment->startCorrection();
            $assessment->stripe_unit_amount = 0;
            $assessment->stripe_paid_session_id = 'free';
            $assessment->save();
            return ['url' => null, 'files' => $files];
        }
        if ($unitAmount < self::MIN_CHARGE) {
            throw new WSException('Discounted price is below the minimum charge of 0.50 €', 400);
        }

        $session = $this->stripe->createCheckoutSession([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => $count,
                'price_data' => [
                    'currency' => self::CURRENCY,
                    'unit_amount' => $unitAmount,
                    'product_data' => [
                        'name' => 'Correction',
                    ],
                ],
            ]],
            'success_url' => $this->assessmentUrl($assessment->id, '?checkout=success&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => $this->assessmentUrl($assessment->id, '?checkout=cancel'),
            'metadata' => [
                'assessment_id' => $assessment->id,
                'user_id' => $assessment->user_id,
                'unit_amount' => (string) $unitAmount,
            ],
            'client_reference_id' => $assessment->id,
        ]);

        $assessment->stripe_checkout_session_id = (string) $session->id;
        $assessment->stripe_unit_amount = $unitAmount;
        $assessment->save();

        $url = (string) $session->url;
        if ($url === '') {
            throw new WSException('Stripe did not return a checkout URL', 502);
        }
        return ['url' => $url, 'files' => null];
    }

    /**
     * Start correction once when this Checkout Session is paid for the assessment.
     *
     * @return array Updated file list
     */
    public function fulfill(BaseAssessment $assessment, string $sessionId): array
    {
        if ($sessionId === '') {
            throw new WSException('Payment required', 402);
        }
        if ($assessment->stripe_paid_session_id === $sessionId) {
            return $assessment->list_files();
        }

        $session = $this->stripe->retrieveSession($sessionId);
        $this->assertPayable($session, $assessment, count($assessment->unclassifiedFileIds()));

        if ($assessment->id === null || $assessment->id === '') {
            throw new WSException('Assessment id is required', 400);
        }
        $fresh = Assessment::from_hash($assessment->id);
        if ($fresh->stripe_paid_session_id === $sessionId) {
            return $fresh->list_files();
        }

        $fresh->stripe_paid_session_id = $sessionId;
        $fresh->save();
        try {
            return $fresh->startCorrection();
        } catch (\Throwable $e) {
            $fresh->stripe_paid_session_id = '';
            $fresh->save();
            throw $e;
        }
    }

    public function assertPayable(Session $session, BaseAssessment $assessment, int $copyCount): void
    {
        if ($copyCount < 1) {
            throw new WSException('No copies to correct', 400);
        }
        if ((string) $session->payment_status !== 'paid') {
            throw new WSException('Payment required', 402);
        }
        if (strtolower((string) $session->currency) !== self::CURRENCY) {
            throw new WSException('Unexpected payment currency', 400);
        }
        if ((int) $session->amount_total !== $copyCount * $assessment->stripe_unit_amount) {
            throw new WSException('Payment amount does not match the copies', 400);
        }
        if ((string) $session->id === '' || (string) $session->id !== $assessment->stripe_checkout_session_id) {
            throw new WSException('Payment does not match this assessment', 400);
        }

        $metadata = $session->metadata;
        $assessmentId = $metadata === null ? '' : (string) ($metadata['assessment_id'] ?? '');
        $userId = $metadata === null ? '' : (string) ($metadata['user_id'] ?? '');
        if ($assessmentId !== (string) $assessment->id || $userId !== $assessment->user_id) {
            throw new WSException('Payment does not match this assessment', 400);
        }
    }

    private function assessmentUrl(string $assessmentId, string $query): string
    {
        $origin = $_ENV['CLIENT_ORIGIN'] ?? '';
        if (!is_string($origin) || trim($origin) === '') {
            throw new WSException('CLIENT_ORIGIN is not configured', 500);
        }
        return rtrim($origin, '/') . '/assessment/' . rawurlencode($assessmentId) . $query;
    }

    private function unitAmountFor(BaseAssessment $assessment): int
    {
        $user = User::from_hash($assessment->user_id);
        return self::unitAmount($user->discount_rate);
    }
}
