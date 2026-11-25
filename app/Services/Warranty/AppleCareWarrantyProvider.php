<?php

namespace App\Services\Warranty;

use App\Models\Asset;
use App\SyncAdapters\AppleBusinessManager\AppleBusinessManagerClient;
use Carbon\CarbonImmutable;

/**
 * AppleCare coverage via the Apple Business Manager API. Apple publishes no
 * open serial-number coverage lookup (the public checkcoverage page is
 * captcha-protected), so this only works for devices enrolled in the
 * organization's ABM account, where the device id is the serial number.
 */
class AppleCareWarrantyProvider implements WarrantyProvider
{
    public function __construct(private readonly ?AppleBusinessManagerClient $client = null) {}

    public function key(): string
    {
        return 'apple';
    }

    public function isConfigured(): bool
    {
        return $this->client !== null || (
            filled(config('warranty.apple.client_id'))
            && filled(config('warranty.apple.key_id'))
            && filled(config('warranty.apple.private_key'))
        );
    }

    public function supports(Asset $asset): bool
    {
        return stripos((string) $asset->model?->manufacturer?->name, 'apple') !== false;
    }

    public function lookup(string $serial): array
    {
        $client = $this->client ?? new AppleBusinessManagerClient(
            (string) config('warranty.apple.client_id'),
            (string) config('warranty.apple.key_id'),
            (string) config('warranty.apple.private_key'),
            (string) config('warranty.apple.mode', 'business'),
        );

        $coverages = [];

        foreach ($client->deviceAppleCareCoverage($serial) as $plan) {
            $attributes = $plan['attributes'] ?? $plan;

            if (($attributes['isCanceled'] ?? false) === true) {
                continue;
            }

            $coverages[] = new WarrantyCoverage(
                reference: $attributes['agreementNumber'] ?? ($plan['id'] ?? null),
                serviceLevel: $attributes['description'] ?? null,
                coverageType: $attributes['paymentType'] ?? null,
                startsAt: $this->date($attributes['startDateTime'] ?? null),
                endsAt: $this->date($attributes['endDateTime'] ?? null),
            );
        }

        return $coverages;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse($value)->startOfDay() : null;
    }
}
