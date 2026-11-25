<?php

namespace App\Services\Warranty;

use Carbon\CarbonImmutable;

/**
 * Normalized coverage entry returned by a WarrantyProvider.
 */
final class WarrantyCoverage
{
    public function __construct(
        public readonly ?string $reference,
        public readonly ?string $serviceLevel,
        public readonly ?string $coverageType,
        public readonly ?CarbonImmutable $startsAt,
        public readonly ?CarbonImmutable $endsAt,
    ) {}
}
