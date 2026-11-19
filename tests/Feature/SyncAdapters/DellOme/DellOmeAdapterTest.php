<?php

namespace Tests\Feature\SyncAdapters\DellOme;

use App\Models\Statuslabel;
use App\Models\SyncAdapterConfig;
use App\Models\SyncAdapterInstance;
use App\Models\User;
use App\SyncAdapters\DellOme\DellOmeAdapter;
use App\SyncAdapters\SyncAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end coverage for the Dell OpenManage Enterprise adapter
 * through SyncAdapter. Mocks the OME REST API ($top/$skip paging over
 * the OData envelope) and asserts the normalized assets.
 */
class DellOmeAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Statuslabel::factory()->rtd()->create();
    }

    public function test_pulls_devices_across_pages_and_creates_assets(): void
    {
        $adapter = $this->configuredAdapter();
        Http::fake([
            '*/api/DeviceService/Devices*' => Http::sequence()
                ->push(['value' => [$this->device(id: 101, tag: 'ABC1234')], '@odata.nextLink' => '/api/DeviceService/Devices?$skip=100&$top=100'])
                ->push(['value' => [$this->device(id: 102, tag: 'DEF5678')]]),
        ]);

        foreach ($adapter->pull() as $record) {
            SyncAdapter::syncFromRecord($record);
        }

        $this->assertDatabaseHas('asset_external_sources', ['source' => 'dell_ome', 'external_id' => '101']);
        $this->assertDatabaseHas('asset_external_sources', ['source' => 'dell_ome', 'external_id' => '102']);
        $this->assertDatabaseHas('assets', ['serial' => 'ABC1234']);
        $this->assertDatabaseHas('assets', ['serial' => 'DEF5678']);
        Http::assertSentCount(2);
    }

    public function test_normalized_record_carries_expected_fields(): void
    {
        $adapter = $this->configuredAdapter();
        Http::fake(['*/api/DeviceService/Devices*' => Http::response(['value' => [$this->device(id: 7, tag: 'XYZ9876')]])]);

        $record = iterator_to_array($adapter->pull(), false)[0];

        $this->assertSame('7', $record->sourceId);
        $this->assertSame('XYZ9876', $record->hardwareSerial);
        $this->assertSame('PowerEdge R750', $record->hardwareModel);
        $this->assertSame('Dell', $record->manufacturer);
        $this->assertSame('esx-01.example.com', $record->hostname);
        $this->assertSame('10.1.1.7', $record->primaryIp);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $record->primaryMac);
        $this->assertSame('2026-03-01', $record->lastSeen->format('Y-m-d'));
        $this->assertSame('Server', $record->extra['ome_device_type']);
        $this->assertSame('On', $record->extra['ome_power_state']);
        $this->assertSame('Warning', $record->extra['ome_status']);
        $this->assertTrue($record->extra['ome_connected']);
        $this->assertSame('7.00.30.00', $record->extra['ome_management_firmware']);
    }

    public function test_falls_back_to_identifier_and_management_dns_name(): void
    {
        $adapter = $this->configuredAdapter();
        $device = $this->device(id: 8, tag: 'ignored');
        unset($device['DeviceServiceTag']);
        $device['DeviceName'] = '';
        Http::fake(['*/api/DeviceService/Devices*' => Http::response(['value' => [$device]])]);

        $record = iterator_to_array($adapter->pull(), false)[0];

        $this->assertSame('ID-8', $record->hardwareSerial);
        $this->assertSame('idrac-8.example.com', $record->hostname);
    }

    public function test_skips_devices_without_an_id(): void
    {
        $adapter = $this->configuredAdapter();
        $broken = $this->device(id: 9, tag: 'BROKEN');
        unset($broken['Id']);
        Http::fake(['*/api/DeviceService/Devices*' => Http::response(['value' => [$broken, $this->device(id: 10, tag: 'GOOD')]])]);

        $records = iterator_to_array($adapter->pull(), false);

        $this->assertCount(1, $records);
        $this->assertSame('10', $records[0]->sourceId);
    }

    public function test_sends_basic_auth_and_odata_paging_parameters(): void
    {
        $adapter = $this->configuredAdapter();
        Http::fake(['*/api/DeviceService/Devices*' => Http::response(['value' => []])]);

        iterator_to_array($adapter->pull(), false);

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Basic '.base64_encode('admin:fake-password')]
            && str_contains(urldecode($request->url()), '$top=100')
            && str_contains(urldecode($request->url()), '$skip=0')
            && ! str_contains(urldecode($request->url()), '$filter'));
    }

    public function test_device_type_setting_filters_by_ome_type_code(): void
    {
        $adapter = $this->configuredAdapter(['device_type' => 'chassis']);
        Http::fake(['*/api/DeviceService/Devices*' => Http::response(['value' => []])]);

        iterator_to_array($adapter->pull(), false);

        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), '$filter=Type eq 2000'));
    }

    public function test_tls_verification_is_on_unless_explicitly_skipped(): void
    {
        $this->assertTrue($this->clientVerifyOption($this->configuredAdapter()));
        $this->assertFalse($this->clientVerifyOption($this->configuredAdapter(['skip_tls_verify' => '1'])));
    }

    public function test_settings_page_renders_the_adapter_schema(): void
    {
        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.adapters.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Dell OpenManage Enterprise', $html);
        $this->assertMatchesRegularExpression('/name="dell_ome_password"/', $html);
        $this->assertMatchesRegularExpression('/name="dell_ome_device_type"/', $html);
    }

    /**
     * @param  array<string, string>  $extraConfig
     */
    private function configuredAdapter(array $extraConfig = []): DellOmeAdapter
    {
        $instance = SyncAdapterInstance::where('slug', 'dell_ome')->firstOrFail();
        SyncAdapterConfig::put($instance->id, 'url', 'https://ome.example.com');
        SyncAdapterConfig::put($instance->id, 'username', 'admin');
        SyncAdapterConfig::put($instance->id, 'password', Crypt::encrypt('fake-password'));
        foreach ($extraConfig as $key => $value) {
            SyncAdapterConfig::put($instance->id, $key, $value);
        }

        return new DellOmeAdapter($instance->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function device(int $id, string $tag): array
    {
        return [
            'Id' => $id,
            'Type' => 1000,
            'Identifier' => 'ID-'.$id,
            'DeviceServiceTag' => $tag,
            'Model' => 'PowerEdge R750',
            'DeviceName' => 'esx-01.example.com',
            'PowerState' => 17,
            'Status' => 3000,
            'ConnectionState' => true,
            'ChassisServiceTag' => null,
            'LastInventoryTime' => '2026-02-28T22:00:00.000Z',
            'LastStatusTime' => '2026-03-01T08:30:00.000Z',
            'DeviceManagement' => [
                [
                    'NetworkAddress' => '10.1.1.'.$id,
                    'MacAddress' => 'AA:BB:CC:DD:EE:FF',
                    'DnsName' => 'idrac-'.$id.'.example.com',
                    'ManagementProfile' => [['Version' => '7.00.30.00']],
                ],
            ],
        ];
    }

    private function clientVerifyOption(DellOmeAdapter $adapter): bool
    {
        $method = new \ReflectionMethod($adapter, 'client');
        $client = $method->invoke($adapter);
        $property = new \ReflectionProperty($client, 'verifyTls');

        return $property->getValue($client);
    }
}
