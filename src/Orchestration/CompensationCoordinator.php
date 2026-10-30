<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Orchestration\Contracts\ManualCompensation;

/** Trusted operator port: the product receipt commits before the local completion. */
final class CompensationCoordinator
{
    public function __construct(private PostgresStore $store, private ManualCompensation $product)
    {
    }

    /** @return array<string, mixed> */
    public function acknowledge(string $reference, string $actor, string $evidence, string $key): array
    {
        foreach ([$actor, $evidence, $key] as $value) {
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $value)) {
                throw new PaymentError('invalid_compensation_acknowledgement', 422);
            }
        }
        $intent = $this->store->intent($reference) ?? throw new PaymentError('not_found', 404);
        if ($intent['fulfillment'] !== 'compensation_requested') {
            throw new PaymentError('compensation_not_requested', 409);
        }
        $hash = hash('sha256', $reference.'/'.$key);
        $receipt = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
        $ack = ['version' => 1, 'kind' => 'manual_business_resolution', 'reference' => $reference, 'receiptId' => $receipt, 'actor' => $actor, 'evidence' => $evidence, 'requestKey' => $key];
        if (isset($intent['compensation_ack']) && CanonicalJson::encode($intent['compensation_ack']) !== CanonicalJson::encode($ack)) {
            throw new PaymentError('compensation_acknowledgement_conflict', 409);
        }
        if (($intent['compensation'] ?? null) === 'completed' && !isset($intent['compensation_ack'])) {
            throw new PaymentError('compensation_already_completed', 409);
        }
        // A crash between these commits is repaired by replaying the same receipt.
        // No provider call, transaction, refund ID or refunded amount is invented.
        $this->product->acknowledgeCompensation($receipt, $intent['subject'], $ack);
        $this->store->completeManualCompensation($reference, $ack);
        return ['compensationState' => 'completed', 'receiptId' => $receipt];
    }
}
