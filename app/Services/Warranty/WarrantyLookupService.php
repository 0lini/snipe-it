<?php

namespace App\Services\Warranty;

use App\Models\Asset;
use App\Models\AssetWarrantyCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WarrantyLookupService
{
    /** Upper bound enforced by Asset::$rules for warranty_months. */
    private const MAX_WARRANTY_MONTHS = 240;

    /**
     * @param  array<int, WarrantyProvider>|null  $providers
     */
    public function __construct(private ?array $providers = null) {}

    /**
     * @return array<int, WarrantyProvider>
     */
    public function providers(): array
    {
        return $this->providers ??= [
            new DellWarrantyProvider,
            new AppleCareWarrantyProvider,
        ];
    }

    public function providerFor(Asset $asset): ?WarrantyProvider
    {
        foreach ($this->providers() as $provider) {
            if ($provider->isConfigured() && $provider->supports($asset)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Look up and persist coverage for one asset.
     *
     * Returns the stored coverage entries, or null when the asset was not
     * eligible (no serial, no configured provider). Provider exceptions
     * propagate so a failed call is retried on the next run instead of
     * being recorded as "no coverage".
     *
     * @return array<int, WarrantyCoverage>|null
     */
    public function refresh(Asset $asset, bool $apply = true): ?array
    {
        $serial = trim((string) $asset->serial);
        $provider = $serial !== '' ? $this->providerFor($asset) : null;

        if ($provider === null) {
            return null;
        }

        $coverages = $provider->lookup($serial);
        $now = now();

        DB::transaction(function () use ($asset, $provider, $coverages, $now) {
            AssetWarrantyCoverage::query()
                ->where('asset_id', $asset->id)
                ->where('provider', $provider->key())
                ->delete();

            foreach ($coverages as $coverage) {
                AssetWarrantyCoverage::query()->create([
                    'asset_id' => $asset->id,
                    'provider' => $provider->key(),
                    'reference' => $coverage->reference,
                    'service_level' => $coverage->serviceLevel,
                    'coverage_type' => $coverage->coverageType,
                    'starts_at' => $coverage->startsAt,
                    'ends_at' => $coverage->endsAt,
                    'checked_at' => $now,
                ]);
            }

            Asset::query()->whereKey($asset->id)->update(['warranty_last_checked_at' => $now]);
        });

        if ($apply) {
            $this->applyToAsset($asset, $coverages);
        }

        return $coverages;
    }

    /**
     * Snipe-IT derives warranty_expires from purchase_date + warranty_months,
     * so the vendor end date is converted into a month count. The asset is
     * only ever extended, never shortened, so hand-entered data is not lost.
     *
     * @param  array<int, WarrantyCoverage>  $coverages
     */
    public function applyToAsset(Asset $asset, array $coverages): bool
    {
        $latestEnd = collect($coverages)->pluck('endsAt')->filter()->max();

        if ($latestEnd === null) {
            return false;
        }

        $purchaseDate = $asset->purchase_date
            ? CarbonImmutable::parse($asset->purchase_date)->startOfDay()
            : collect($coverages)->pluck('startsAt')->filter()->min();

        if ($purchaseDate === null || $purchaseDate->greaterThanOrEqualTo($latestEnd)) {
            return false;
        }

        $months = ($latestEnd->year - $purchaseDate->year) * 12 + ($latestEnd->month - $purchaseDate->month);
        if ($purchaseDate->addMonthsNoOverflow($months)->lessThan($latestEnd)) {
            $months++;
        }
        $months = min($months, self::MAX_WARRANTY_MONTHS);

        if ($asset->warranty_months !== null && (int) $asset->warranty_months >= $months) {
            return false;
        }

        $values = ['warranty_months' => $months];
        if (! $asset->purchase_date) {
            $values['purchase_date'] = $purchaseDate->toDateString();
        }

        Asset::query()->whereKey($asset->id)->update($values);

        return true;
    }
}
