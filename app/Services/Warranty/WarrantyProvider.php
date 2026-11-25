<?php

namespace App\Services\Warranty;

use App\Models\Asset;

interface WarrantyProvider
{
    /**
     * Short stable key stored in asset_warranty_coverages.provider.
     */
    public function key(): string;

    /**
     * Whether credentials for this provider are present.
     */
    public function isConfigured(): bool;

    /**
     * Whether this provider can look up the given asset, normally decided
     * by the manufacturer name of the asset's model.
     */
    public function supports(Asset $asset): bool;

    /**
     * Look up coverage for a serial number. An empty array means the vendor
     * knows the device but reports no coverage. Throw on transport or
     * auth errors so the caller does not record a false "no coverage".
     *
     * @return array<int, WarrantyCoverage>
     */
    public function lookup(string $serial): array;
}
