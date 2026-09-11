<?php

namespace Tests\Feature\SyncAdapters\NetBox;

use App\Models\Statuslabel;
use App\Models\SyncAdapterConfig;
use App\Models\SyncAdapterInstance;
use App\SyncAdapters\NetBox\NetBoxAdapter;
use App\SyncAdapters\SyncHostFromAdapter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end coverage for the NetBox adapter through SyncHostFromAdapter.
 * Mocks the NetBox REST API, asserts assets + asset_external_sources land
 * correctly, and that Token vs Bearer auth headers match the token shape.
 */
class NetBoxAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Statuslabel::factory()->rtd()->create();
    }

    public function test_pulls_devices_from_netbox_and_creates_assets()
    {
        $adapter = $this->configuredNetBoxAdapter();

        Http::fake([
            '*/api/dcim/devices/*' => Http::response([
                'count' => 2,
                'next' => null,
                'previous' => null,
                'results' => [
                    $this->netboxDevice(id: 11, name: 'core-sw-01', model: 'C9300-48P'),
                    $this->netboxDevice(id: 22, name: 'edge-fw-01', model: 'PA-3220'),
                ],
            ]),
        ]);

        foreach ($adapter->pull() as $record) {
            SyncHostFromAdapter::run($record);
        }

        $this->assertDatabaseCount('asset_external_sources', 2);
        $this->assertDatabaseHas('asset_external_sources', ['source' => 'netbox', 'external_id' => '11']);
        $this->assertDatabaseHas('asset_external_sources', ['source' => 'netbox', 'external_id' => '22']);
        $this->assertDatabaseHas('assets', ['name' => 'core-sw-01']);
    }

    public function test_normalized_record_carries_expected_fields()
    {
        $adapter = $this->configuredNetBoxAdapter();

        Http::fake([
            '*/api/dcim/devices/*' => Http::response([
                'count' => 1,
                'next' => null,
                'previous' => null,
                'results' => [
                    $this->netboxDevice(
                        id: 7,
                        name: 'leaf-01',
                        model: 'N9K-C93180YC-EX',
                        serial: 'FOX123456',
                        manufacturer: 'Cisco',
                        assetTag: 'NB-0007',
                        primaryIp: '10.0.0.7/24',
                        platform: 'NX-OS',
                        siteId: 3,
                        siteName: 'DC1',
                        status: 'active',
                        role: 'Leaf Switch',
                        tenant: 'Corp',
                        rack: 'R1',
                        location: 'Row A',
                    ),
                ],
            ]),
        ]);

        $records = iterator_to_array($adapter->pull());
        $this->assertCount(1, $records);

        $record = $records[0];
        $this->assertSame('netbox', $record->sourceKey);
        $this->assertSame('7', $record->sourceId);
        $this->assertSame('leaf-01', $record->hostname);
        $this->assertSame('N9K-C93180YC-EX', $record->hardwareModel);
        $this->assertSame('FOX123456', $record->hardwareSerial);
        $this->assertSame('Cisco', $record->manufacturer);
        $this->assertSame('10.0.0.7', $record->primaryIp);
        $this->assertSame('NX-OS', $record->os);
        $this->assertSame('NB-0007', $record->assetTag);
        $this->assertSame('3', $record->vendorGroupId);
        $this->assertSame('DC1', $record->extra['netbox_site']);
        $this->assertSame('Leaf Switch', $record->extra['netbox_role']);
        $this->assertSame('active', $record->extra['netbox_status']);
        $this->assertSame('Corp', $record->extra['netbox_tenant']);
        $this->assertSame('R1', $record->extra['netbox_rack']);
        $this->assertSame('Row A', $record->extra['netbox_location']);
        $this->assertNull($record->lastSeen);
        $this->assertNull($record->primaryMac);
    }

    public function test_legacy_token_uses_token_authorization_header()
    {
        $adapter = $this->configuredNetBoxAdapter(token: '0123456789abcdef0123456789abcdef01234567');

        Http::fake([
            '*/api/dcim/devices/*' => Http::response([
                'count' => 0,
                'next' => null,
                'previous' => null,
                'results' => [],
            ]),
        ]);

        iterator_to_array($adapter->pull());

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Token 0123456789abcdef0123456789abcdef01234567');
        });
    }

    public function test_v2_nbt_token_uses_bearer_authorization_header()
    {
        $v2 = 'nbt_abcdefghijkl.abcdefghijklmnopqrstuvwxyz0123456789ABCDEF';
        $adapter = $this->configuredNetBoxAdapter(token: $v2);

        Http::fake([
            '*/api/dcim/devices/*' => Http::response([
                'count' => 0,
                'next' => null,
                'previous' => null,
                'results' => [],
            ]),
        ]);

        iterator_to_array($adapter->pull());

        Http::assertSent(function ($request) use ($v2) {
            return $request->hasHeader('Authorization', 'Bearer '.$v2);
        });
    }

    public function test_fetch_groups_returns_netbox_sites()
    {
        $adapter = $this->configuredNetBoxAdapter();

        Http::fake([
            '*/api/dcim/sites/*' => Http::response([
                'count' => 2,
                'next' => null,
                'previous' => null,
                'results' => [
                    ['id' => 1, 'name' => 'HQ'],
                    ['id' => 2, 'name' => 'Branch'],
                ],
            ]),
        ]);

        $groups = $adapter->fetchGroups();

        $this->assertSame([
            ['id' => '1', 'label' => 'HQ'],
            ['id' => '2', 'label' => 'Branch'],
        ], $groups);
    }

    private function configuredNetBoxAdapter(string $token = 'fake-netbox-token'): NetBoxAdapter
    {
        $instance = SyncAdapterInstance::where('slug', 'netbox')->firstOrFail();
        SyncAdapterConfig::put($instance->id, 'url', 'https://netbox.example.test');
        SyncAdapterConfig::put($instance->id, 'token', Crypt::encrypt($token));

        return new NetBoxAdapter($instance->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function netboxDevice(
        int $id,
        string $name = 'device',
        string $model = 'Generic',
        ?string $serial = null,
        ?string $manufacturer = null,
        ?string $assetTag = null,
        ?string $primaryIp = null,
        ?string $platform = null,
        ?int $siteId = null,
        ?string $siteName = null,
        ?string $status = null,
        ?string $role = null,
        ?string $tenant = null,
        ?string $rack = null,
        ?string $location = null,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'display' => $name,
            'serial' => $serial,
            'asset_tag' => $assetTag,
            'status' => $status !== null ? ['value' => $status, 'label' => ucfirst($status)] : null,
            'device_type' => [
                'id' => 1,
                'model' => $model,
                'display' => $model,
                'manufacturer' => $manufacturer !== null
                    ? ['id' => 1, 'name' => $manufacturer, 'display' => $manufacturer]
                    : null,
            ],
            'role' => $role !== null ? ['id' => 1, 'name' => $role, 'display' => $role] : null,
            'site' => $siteId !== null
                ? ['id' => $siteId, 'name' => $siteName ?? 'Site '.$siteId, 'display' => $siteName ?? 'Site '.$siteId]
                : null,
            'location' => $location !== null ? ['id' => 1, 'name' => $location, 'display' => $location] : null,
            'rack' => $rack !== null ? ['id' => 1, 'name' => $rack, 'display' => $rack] : null,
            'tenant' => $tenant !== null ? ['id' => 1, 'name' => $tenant, 'display' => $tenant] : null,
            'platform' => $platform !== null ? ['id' => 1, 'name' => $platform, 'display' => $platform] : null,
            'primary_ip' => $primaryIp !== null
                ? ['id' => 1, 'address' => $primaryIp, 'family' => 4]
                : null,
        ];
    }
}
