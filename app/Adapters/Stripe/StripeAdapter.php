<?php

declare(strict_types=1);

namespace App\Adapters\Stripe;

use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * StripeAdapter
 *
 * Thin wrapper around the Stripe PHP SDK.
 * All operations use idempotency keys to prevent duplicate charges.
 */
class StripeAdapter
{
    private ?StripeClient $client = null;
    private string $currency;

    public function __construct(array $config = [])
    {
        // Auto-load from config/apis.php if no config passed
        if (empty($config)) {
            $config = \App\Helpers\ConfigLoader::load('apis')['stripe'] ?? [];
        }

        $secretKey = $config['secret_key'] ?? (getenv('STRIPE_SECRET_KEY') ?: '');
        $this->currency = strtolower($config['currency'] ?? (getenv('STRIPE_CURRENCY') ?: 'gbp'));

        // Defer throwing until an actual payment operation is attempted
        if (!empty($secretKey)) {
            $this->client = new StripeClient($secretKey);
        }
    }

    private function requireClient(): StripeClient
    {
        if ($this->client === null) {
            throw new \RuntimeException('خدمة الدفع غير متاحة حالياً. الرجاء التواصل مع الدعم.');
        }
        return $this->client;
    }

    // =========================================================================
    // Payment Intents
    // =========================================================================

    /**
     * Create a Stripe PaymentIntent.
     *
     * @param  int    $amountInMinorUnits  Amount in smallest currency unit (pence for GBP).
     * @param  string $idempotencyKey      Unique key to prevent duplicate charges.
     * @param  string $currency            ISO 4217 lowercase (default: gbp).
     * @param  array  $metadata            Key-value metadata (booking_type, booking_id, user_id, etc.)
     * @param  array  $paymentMethodTypes  e.g. ['card'] or ['card', 'link']
     * @return array  ['client_secret' => ..., 'payment_intent_id' => ...]
     */
    public function createPaymentIntent(
        int $amountInMinorUnits,
        string $idempotencyKey,
        string $currency = '',
        array $metadata = [],
        array $paymentMethodTypes = ['card']
    ): array {
        $currency = $currency ?: $this->currency;

        try {
            $intent = $this->requireClient()->paymentIntents->create(
                [
                    'amount'               => $amountInMinorUnits,
                    'currency'             => $currency,
                    'payment_method_types' => $paymentMethodTypes,
                    'metadata'             => $metadata,
                    'capture_method'       => 'automatic',
                ],
                ['idempotency_key' => $idempotencyKey]
            );

            return [
                'payment_intent_id' => $intent->id,
                'client_secret'     => $intent->client_secret,
                'status'            => $intent->status,
                'amount'            => $intent->amount,
                'currency'          => $intent->currency,
            ];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe PaymentIntent creation failed: ' . $e->getMessage(), (int)$e->getHttpStatus(), $e);
        }
    }

    /**
     * Retrieve an existing PaymentIntent by ID.
     */
    public function getPaymentIntent(string $paymentIntentId): array
    {
        try {
            $intent = $this->requireClient()->paymentIntents->retrieve($paymentIntentId);
            return $intent->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe PaymentIntent retrieval failed: ' . $e->getMessage(), (int)$e->getHttpStatus(), $e);
        }
    }

    /**
     * Cancel a PaymentIntent (before capture).
     */
    public function cancelPaymentIntent(string $paymentIntentId, string $reason = 'abandoned'): array
    {
        try {
            $intent = $this->requireClient()->paymentIntents->cancel(
                $paymentIntentId,
                ['cancellation_reason' => $reason]
            );
            return $intent->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe PaymentIntent cancellation failed: ' . $e->getMessage(), (int)$e->getHttpStatus(), $e);
        }
    }

    // =========================================================================
    // Refunds
    // =========================================================================

    /**
     * Issue a full or partial refund.
     *
     * @param  string   $paymentIntentId
     * @param  int|null $amountInMinorUnits  Null = full refund.
     * @param  string   $idempotencyKey
     * @param  string   $reason   duplicate | fraudulent | requested_by_customer
     */
    public function createRefund(
        string $paymentIntentId,
        ?int $amountInMinorUnits = null,
        string $idempotencyKey = '',
        string $reason = 'requested_by_customer'
    ): array {
        $params = [
            'payment_intent' => $paymentIntentId,
            'reason'         => $reason,
        ];

        if ($amountInMinorUnits !== null) {
            $params['amount'] = $amountInMinorUnits;
        }

        $options = [];
        if (!empty($idempotencyKey)) {
            $options['idempotency_key'] = $idempotencyKey;
        }

        try {
            $refund = $this->requireClient()->refunds->create($params, $options ?: null);
            return $refund->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe refund failed: ' . $e->getMessage(), (int)$e->getHttpStatus(), $e);
        }
    }

    // =========================================================================
    // Customers
    // =========================================================================

    /**
     * Create or retrieve a Stripe Customer (for saved payment methods).
     */
    public function createCustomer(string $email, string $name, array $metadata = []): array
    {
        try {
            $customer = $this->requireClient()->customers->create([
                'email'    => $email,
                'name'     => $name,
                'metadata' => $metadata,
            ]);
            return $customer->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe Customer creation failed: ' . $e->getMessage(), (int)$e->getHttpStatus(), $e);
        }
    }

    // =========================================================================
    // Webhook event construction (for testing)
    // =========================================================================

    /**
     * Construct a Stripe Event from a raw payload + signature.
     * Use this in the webhook controller to validate and parse events.
     *
     * @throws \Stripe\Exception\SignatureVerificationException
     */
    public function constructWebhookEvent(string $payload, string $sigHeader, string $webhookSecret): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
    }
}
