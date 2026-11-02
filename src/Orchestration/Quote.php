<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration;

use Ronu\PaymentGateways\Money;

final readonly class Quote
{
    /**
     * @param bool $shared     More than one actor may pay this subject (tenant- or link-owned): the coordinator
     *                         then refuses a second active payment from ANOTHER actor, not only from the same one.
     * @param bool $retryable  After an attempt that ended without money (declined, canceled) a new attempt may
     *                         start; it is stored as `<subject>#<n>` (see SubjectAttempt).
     * @param string|null $description Short text for the provider page (item title).
     */
    public function __construct(
        public string $subject,
        public string $revision,
        public string $ownerScope,
        public Money $amount,
        public int $expiresAt,
        public bool $shared = false,
        public bool $retryable = false,
        public ?string $description = null,
    ) {
        if ($subject === '' || strlen($subject) > 128 || $revision === '' || strlen($revision) > 128 || $amount->isZero()
            || ($description !== null && ($description === '' || mb_strlen($description) > 256))) {
            throw new PaymentError('invalid_quote', 422);
        }
    }
}
