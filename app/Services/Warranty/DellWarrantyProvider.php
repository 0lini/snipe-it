<?php

namespace App\Services\Warranty;

use App\Models\Asset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Dell TechDirect "Asset Entitlements" API (v5). The Dell service tag is
 * the serial number Snipe-IT already stores for the asset.
 *
 * Auth is OAuth2 client credentials; the bearer token is cached until
 * shortly before it expires.
 */
class DellWarrantyProvider implements WarrantyProvider
{
    private const CACHE_KEY = 'warranty:dell:token';

    public function key(): string
    {
        return 'dell';
    }

    public function isConfigured(): bool
    {
        return filled(config('warranty.dell.client_id')) && filled(config('warranty.dell.client_secret'));
    }

    public function supports(Asset $asset): bool
    {
        return stripos((string) $asset->model?->manufacturer?->name, 'dell') !== false;
    }

    public function lookup(string $serial): array
    {
        $response = Http::withToken($this->token())
            ->timeout(15)
            ->acceptJson()
            ->get(rtrim((string) config('warranty.dell.base_url'), '/').'/PROD/sbil/eapi/v5/asset-entitlements', [
                'servicetags' => $serial,
            ])
            ->throw();

        $coverages = [];

        foreach ((array) $response->json() as $record) {
            if (($record['invalid'] ?? false) === true) {
                continue;
            }

            foreach ($record['entitlements'] ?? [] as $entitlement) {
                $coverages[] = new WarrantyCoverage(
                    reference: $entitlement['itemNumber'] ?? $entitlement['serviceLevelCode'] ?? null,
                    serviceLevel: $entitlement['serviceLevelDescription'] ?? null,
                    coverageType: $entitlement['entitlementType'] ?? null,
                    startsAt: $this->date($entitlement['startDate'] ?? null),
                    endsAt: $this->date($entitlement['endDate'] ?? null),
                );
            }
        }

        return $coverages;
    }

    private function token(): string
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(50), function () {
            return Http::asForm()
                ->timeout(15)
                ->post(rtrim((string) config('warranty.dell.base_url'), '/').'/auth/oauth/v2/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('warranty.dell.client_id'),
                    'client_secret' => config('warranty.dell.client_secret'),
                ])
                ->throw()
                ->json('access_token');
        });
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse($value)->startOfDay() : null;
    }
}
