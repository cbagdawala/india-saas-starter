<?php

namespace App\Modules\Billing\Data;

/** What the SPA needs to open a checkout: gateway reference + driver-specific payload. */
final readonly class Checkout
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $driver,
        public string $reference,
        public array $payload = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['driver' => $this->driver, 'reference' => $this->reference] + $this->payload;
    }
}
