<?php

namespace Corrai\Payment;

use Corrai\Utils\Http\WSException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient as SdkClient;
use Stripe\Webhook;

/**
 * Stripe API access using the test keys from the environment.
 */
class StripeClient
{
    public const SECRET_KEY = 'TEST_STRIPE_SECRET';
    public const PUBLIC_KEY = 'TEST_STRIPE_PUBLIC';
    public const HOOK_SECRET_KEY = 'TEST_STRIPE_HOOK_SECRET';

    private SdkClient $client;

    public function __construct(
        private string $secretKey,
        private string $publishableKey,
        private string $hookSecret
    ) {
        $this->client = new SdkClient($secretKey);
    }

    public static function fromEnv(): self
    {
        return new self(
            self::requiredEnv(self::SECRET_KEY),
            self::requiredEnv(self::PUBLIC_KEY),
            self::requiredEnv(self::HOOK_SECRET_KEY)
        );
    }

    public function publishableKey(): string
    {
        return $this->publishableKey;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function createCheckoutSession(array $params): Session
    {
        try {
            return $this->client->checkout->sessions->create($params);
        } catch (ApiErrorException $e) {
            error_log('Stripe checkout create failed: ' . $e->getMessage());
            throw new WSException('Stripe checkout failed', 502);
        }
    }

    public function retrieveSession(string $sessionId): Session
    {
        try {
            return $this->client->checkout->sessions->retrieve($sessionId);
        } catch (ApiErrorException $e) {
            error_log('Stripe checkout retrieve failed: ' . $e->getMessage());
            throw new WSException('Stripe checkout failed', 502);
        }
    }

    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        try {
            return Webhook::constructEvent($payload, $signature, $this->hookSecret);
        } catch (\UnexpectedValueException | SignatureVerificationException $e) {
            error_log('Stripe webhook rejected: ' . $e->getMessage());
            throw new WSException('Invalid Stripe webhook', 400);
        }
    }

    public static function signatureHeader(): string
    {
        $fromServer = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        if (is_string($fromServer) && $fromServer !== '') {
            return $fromServer;
        }
        if (!function_exists('getallheaders')) {
            return '';
        }
        $headers = getallheaders();
        if (!is_array($headers)) {
            return '';
        }
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Stripe-Signature') === 0) {
                return (string) $value;
            }
        }
        return '';
    }

    private static function requiredEnv(string $key): string
    {
        $value = $_ENV[$key] ?? '';
        if (!is_string($value) || trim($value) === '') {
            throw new WSException("Missing $key", 500);
        }
        return $value;
    }
}
