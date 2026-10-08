<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Payment\CorrectionCheckout;
use Corrai\Payment\StripeClient;
use Corrai\Utils\Http\WSException;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\WebhookSignature;

class CorrectionCheckoutTest extends TestCase
{
    public function testFromEnvRequiresTheThreeKeys(): void
    {
        $keys = [StripeClient::SECRET_KEY, StripeClient::PUBLIC_KEY, StripeClient::HOOK_SECRET_KEY];
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
        }

        try {
            $this->expectException(WSException::class);
            StripeClient::fromEnv();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    public function testClientReadsThePublishableKey(): void
    {
        $client = new StripeClient('sk_test_secret', 'pk_test_public', 'whsec_hook');
        $this->assertSame('pk_test_public', $client->publishableKey());
    }

    public function testWebhookRejectsABadSignature(): void
    {
        $client = new StripeClient('sk_test_secret', 'pk_test_public', 'whsec_hook');
        $this->expectException(WSException::class);
        $this->expectExceptionCode(400);
        $client->constructWebhookEvent('{}', 't=1,v1=deadbeef');
    }

    public function testWebhookAcceptsASignedEvent(): void
    {
        $secret = 'whsec_hook';
        $client = new StripeClient('sk_test_secret', 'pk_test_public', $secret);
        $payload = json_encode([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test', 'object' => 'checkout.session']],
        ], JSON_THROW_ON_ERROR);
        $header = WebhookSignature::generateSignatureHeader($payload, $secret);

        $event = $client->constructWebhookEvent($payload, $header);
        $this->assertSame('checkout.session.completed', $event->type);
    }

    public function testPaidSessionMatchesAssessmentAndAmount(): void
    {
        $checkout = new CorrectionCheckout(new StripeClient('sk_test_secret', 'pk_test_public', 'whsec_hook'));
        $assessment = $this->assessment();
        $checkout->assertPayable($this->session('paid', 200), $assessment, 2);
        $this->assertSame('cs_test_123', $assessment->attributePayload()['stripe_checkout_session_id']);
        $this->assertSame('', $assessment->attributePayload()['stripe_paid_session_id']);
    }

    public function testUnpaidSessionIsRejected(): void
    {
        $checkout = new CorrectionCheckout(new StripeClient('sk_test_secret', 'pk_test_public', 'whsec_hook'));
        $this->expectException(WSException::class);
        $this->expectExceptionCode(402);
        $checkout->assertPayable($this->session('unpaid', 100), $this->assessment(), 1);
    }

    public function testDiscountedUnitAmountIsAccepted(): void
    {
        $this->assertSame(100, CorrectionCheckout::unitAmount(0));
        $this->assertSame(75, CorrectionCheckout::unitAmount(25));
        $this->assertSame(50, CorrectionCheckout::unitAmount(50));
        $this->assertSame(0, CorrectionCheckout::unitAmount(100));

        $checkout = new CorrectionCheckout(new StripeClient('sk_test_secret', 'pk_test_public', 'whsec_hook'));
        $assessment = $this->assessment();
        $assessment->stripe_unit_amount = 75;
        $checkout->assertPayable($this->session('paid', 150), $assessment, 2);
    }

    public function testAmountMustMatchCopyCount(): void
    {
        $checkout = new CorrectionCheckout(new StripeClient('sk_test_secret', 'pk_test_public', 'whsec_hook'));
        $this->expectException(WSException::class);
        $this->expectExceptionCode(400);
        $checkout->assertPayable($this->session('paid', 100), $this->assessment(), 2);
    }

    public function testStripeIdsRoundTripThroughAttributes(): void
    {
        $assessment = Assessment::from_array([
            'subject' => 'Other',
            'stripe_checkout_session_id' => 'cs_test_123',
            'stripe_paid_session_id' => 'cs_test_paid',
        ]);
        $this->assertSame('cs_test_123', $assessment->stripe_checkout_session_id);
        $this->assertSame('cs_test_paid', $assessment->stripe_paid_session_id);
        $again = Assessment::from_array($assessment->attributePayload());
        $this->assertSame('cs_test_paid', $again->stripe_paid_session_id);
    }

    public function testStartCorrectionCallsConcreteSubclassMethod(): void
    {
        $called = false;
        $mock = new class($called) extends \Corrai\Subject\DictationFranceCM2\Assessment {
            public function __construct(private bool &$calledRef)
            {
            }
            public function startCorrection(): array
            {
                $this->calledRef = true;
                return ['called'];
            }
        };
        $mock->id = 'test_subclass';
        $mock->user_id = 'user_subclass';
        $mock->school_id = 'school_subclass';

        $res = $mock->startCorrection();
        $this->assertTrue($called);
        $this->assertSame(['called'], $res);
    }

    private function assessment(): Assessment
    {
        $assessment = Assessment::from_array([
            'subject' => 'Other',
            'stripe_checkout_session_id' => 'cs_test_123',
        ]);
        $assessment->id = 'assess1';
        $assessment->user_id = 'teacher1';
        return $assessment;
    }

    private function session(string $status, int $amount): Session
    {
        return Session::constructFrom([
            'id' => 'cs_test_123',
            'payment_status' => $status,
            'currency' => 'eur',
            'amount_total' => $amount,
            'metadata' => [
                'assessment_id' => 'assess1',
                'user_id' => 'teacher1',
            ],
        ]);
    }
}
