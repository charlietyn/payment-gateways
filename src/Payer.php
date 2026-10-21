<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways;

final readonly class Payer
{
    public function __construct(
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        /** National id (DNI, CPF…). Mercado Pago asks for it with tickets. */
        public ?string $documentNumber = null,
        public ?string $documentType = null,
    ) {}

    /** "Ana Pérez Gómez" → first "Ana", last "Pérez Gómez". */
    public static function fromFullName(string $name, ?string $email = null, ?string $document = null): self
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return new self($email, $parts[0] ?? null, $parts[1] ?? null, $document);
    }
}
