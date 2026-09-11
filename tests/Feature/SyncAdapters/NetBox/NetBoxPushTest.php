<?php

namespace Tests\Feature\SyncAdapters\NetBox;

use App\Models\Asset;
use App\Models\AssetExternalSource;
use App\Models\Statuslabel;
use App\Models\SyncAdapterConfig;
use App\Models\SyncAdapterInstance;
use App\SyncAdapters\NetBox\NetBoxAdapter;
use App\SyncAdapters\PushableAdapter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end coverage for the NetBox push path. Verifies the
 * PushableAdapter interface, the PATCH payload shape (top-level
 * asset_tag / comments), and dry-run behavior.
 */
class NetBoxPushTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Statuslabel::factory()->rtd()->create();
    }

    public function test_netbox_adapter_implements_pushable_interface()
    {
        $this->assertInstanceOf(PushableAdapter::class, $this->configuredNetBox());
    }

    public function test_push_asset_tag_sends_patch_to_netbox()
    {
        $adapter = $this->configuredNetBox();
        $instance = SyncAdapterInstance::where('slug', 'netbox')->firstOrFail();
        SyncAdapterConfig::put($instance->id, 'direction.asset_tag', 'push');

        $asset = Asset::factory()->create([
            'asset_tag' => 'SNIPE-NB-42',
        ]);
        AssetExternalSource::create([
            'asset_id' => $asset->id,
            'source' => $adapter->name(),
            'external_id' => '99',
        ]);

        Http::fake([
            '*/api/dcim/devices/*' => Http::response(['id' => 99]),
        ]);

        $adapter->push($asset);

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/api/dcim/devices/99/')
                && ($request->data()['asset_tag'] ?? null) === 'SNIPE-NB-42';
        });
    }

    public function test_push_composed_notes_into_comments()
    {
        $adapter = $this->configuredNetBox();
        $instance = SyncAdapterInstance::where('slug', 'netbox')->firstOrFail();
        SyncAdapterConfig::put($instance->id, 'push_notes_template', 'Tag: {asset_tag}');

        $asset = Asset::factory()->create([
            'asset_tag' => 'COMPOSED-1',
        ]);
        AssetExternalSource::create([
            'asset_id' => $asset->id,
            'source' => $adapter->name(),
            'external_id' => '55',
        ]);

        Http::fake([
            '*/api/dcim/devices/*' => Http::response(['id' => 55]),
        ]);

        $adapter->push($asset);

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/api/dcim/devices/55/')
                && ($request->data()['comments'] ?? null) === 'Tag: COMPOSED-1';
        });
    }

    public function test_push_dry_run_logs_instead_of_sending()
    {
        $adapter = $this->configuredNetBox();
        $instance = SyncAdapterInstance::where('slug', 'netbox')->firstOrFail();
        SyncAdapterConfig::put($instance->id, 'direction.asset_tag', 'push');
        SyncAdapterConfig::put($instance->id, 'push_dry_run', '1');

        $asset = Asset::factory()->create(['asset_tag' => 'DRY-RUN-NB']);
        AssetExternalSource::create([
            'asset_id' => $asset->id,
            'source' => $adapter->name(),
            'external_id' => '77',
        ]);

        Http::fake();

        $adapter->push($asset);

        Http::assertNothingSent();
    }

    public function test_push_skipped_when_asset_has_no_external_source()
    {
        $adapter = $this->configuredNetBox();
        $instance = SyncAdapterInstance::where('slug', 'netbox')->firstOrFail();
        SyncAdapterConfig::put($instance->id, 'direction.asset_tag', 'push');

        $asset = Asset::factory()->create(['asset_tag' => 'NO-SOURCE-NB']);

        Http::fake();

        $adapter->push($asset);

        Http::assertNothingSent();
    }

    public function test_notes_field_target_is_comments()
    {
        $this->assertSame('comments', $this->configuredNetBox()->notesFieldTarget());
    }

    private function configuredNetBox(): NetBoxAdapter
    {
        $instance = SyncAdapterInstance::where('slug', 'netbox')->firstOrFail();
        SyncAdapterConfig::put($instance->id, 'url', 'https://netbox.example.test');
        SyncAdapterConfig::put($instance->id, 'token', Crypt::encrypt('fake-netbox-token'));

        return new NetBoxAdapter($instance->fresh());
    }
}
