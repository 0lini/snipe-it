<?php

namespace Tests\Feature\Warranty;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Manufacturer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LookupWarrantiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'warranty.enabled' => true,
            'warranty.apply_to_asset' => true,
            'warranty.dell.client_id' => 'id',
            'warranty.dell.client_secret' => 'secret',
        ]);
        Cache::forget('warranty:dell:token');
    }

    private function dellAsset(array $attributes = []): Asset
    {
        $model = AssetModel::factory()->create([
            'manufacturer_id' => Manufacturer::factory()->dell()->create()->id,
        ]);

        return Asset::factory()->create(array_merge([
            'model_id' => $model->id,
            'serial' => 'ABC1234',
            'purchase_date' => '2024-01-15',
            'warranty_months' => null,
        ], $attributes));
    }

    private function fakeDell(): void
    {
        Http::fake([
            '*/auth/oauth/v2/token' => Http::response(['access_token' => 'tok']),
            '*/asset-entitlements*' => Http::response([[
                'serviceTag' => 'ABC1234',
                'invalid' => false,
                'entitlements' => [
                    ['itemNumber' => '1', 'entitlementType' => 'INITIAL', 'serviceLevelDescription' => 'Basic Support', 'startDate' => '2024-01-15T00:00:00Z', 'endDate' => '2025-01-15T00:00:00Z'],
                    ['itemNumber' => '2', 'entitlementType' => 'EXTENDED', 'serviceLevelDescription' => 'ProSupport Plus', 'startDate' => '2024-01-15T00:00:00Z', 'endDate' => '2027-01-15T00:00:00Z'],
                ],
            ]]),
        ]);
    }

    public function test_stores_dell_coverage_and_extends_asset_warranty(): void
    {
        $this->fakeDell();
        $asset = $this->dellAsset();

        $this->artisan('snipeit:lookup-warranties')->assertExitCode(0);

        $this->assertDatabaseCount('asset_warranty_coverages', 2);
        $this->assertDatabaseHas('asset_warranty_coverages', [
            'asset_id' => $asset->id,
            'provider' => 'dell',
            'service_level' => 'ProSupport Plus',
        ]);

        $asset->refresh();
        $this->assertSame(36, (int) $asset->warranty_months);
        $this->assertSame('2027-01-15', $asset->warranty_expires->toDateString());
        $this->assertNotNull($asset->warranty_last_checked_at);
    }

    public function test_does_not_shorten_an_existing_longer_warranty(): void
    {
        $this->fakeDell();
        $asset = $this->dellAsset(['warranty_months' => 60]);

        $this->artisan('snipeit:lookup-warranties')->assertExitCode(0);

        $this->assertSame(60, (int) $asset->refresh()->warranty_months);
    }

    public function test_recently_checked_assets_are_skipped_unless_forced(): void
    {
        $this->fakeDell();
        $this->dellAsset(['warranty_last_checked_at' => now()->subDay()]);

        $this->artisan('snipeit:lookup-warranties')->assertExitCode(0);
        Http::assertNothingSent();

        $this->artisan('snipeit:lookup-warranties --force')->assertExitCode(0);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'asset-entitlements'));
    }

    public function test_vendor_failure_is_not_recorded_as_a_check(): void
    {
        Http::fake([
            '*/auth/oauth/v2/token' => Http::response(['access_token' => 'tok']),
            '*/asset-entitlements*' => Http::response([], 500),
        ]);
        $asset = $this->dellAsset();

        $this->artisan('snipeit:lookup-warranties');

        $this->assertNull($asset->refresh()->warranty_last_checked_at);
        $this->assertDatabaseCount('asset_warranty_coverages', 0);
    }

    public function test_assets_from_other_manufacturers_are_ignored(): void
    {
        Http::fake();
        $asset = Asset::factory()->create(['serial' => 'XYZ']);

        $this->artisan('snipeit:lookup-warranties')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertNull($asset->refresh()->warranty_last_checked_at);
    }

    public function test_dry_run_stores_nothing(): void
    {
        $this->fakeDell();
        $asset = $this->dellAsset();

        $this->artisan('snipeit:lookup-warranties --dry-run')->assertExitCode(0);

        $this->assertDatabaseCount('asset_warranty_coverages', 0);
        $this->assertNull($asset->refresh()->warranty_months);
    }
}
