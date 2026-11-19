<?php

namespace Tests\Feature\SyncAdapters\HpeOneView;

use App\Models\Statuslabel;
use App\Models\SyncAdapterConfig;
use App\Models\SyncAdapterInstance;
use App\Models\User;
use App\SyncAdapters\HpeOneView\HpeOneViewAdapter;
use App\SyncAdapters\SyncAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end coverage for the HPE OneView adapter through SyncAdapter.
 * Mocks the OneView REST API (version discovery, session login, paged
 * server-hardware, logout) and asserts the normalized assets.
 */
class HpeOneViewAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Statuslabel::factory()->rtd()->create();
    }

    public function test_pulls_server_hardware_and_creates_assets(): void
    {
        $adapter = $this->configuredAdapter();
        $this->fakeAppliance([
            [$this->server(uuid: 'uuid-1', serial: 'CZ1111', name: 'Encl1, bay 1')],
            [$this->server(uuid: 'uuid-2', serial: 'CZ2222', name: 'Encl1, bay 2')],
        ]);

        foreach ($adapter->pull() as $record) {
            SyncAdapter::syncFromRecord($record);
        }

        $this->assertDatabaseHas('asset_external_sources', ['source' => 'hpe_oneview', 'external_id' => 'uuid-1']);
        $this->assertDatabaseHas('asset_external_sources', ['source' => 'hpe_oneview', 'external_id' => 'uuid-2']);
        $this->assertDatabaseHas('assets', ['serial' => 'CZ1111']);
        $this->assertDatabaseHas('assets', ['serial' => 'CZ2222']);
    }

    public function test_normalized_record_carries_expected_fields(): void
    {
        $adapter = $this->configuredAdapter();
        $this->fakeAppliance([[$this->server(
            uuid: 'uuid-9',
            serial: 'CZ9999',
            name: 'Encl1, bay 9',
            groupUri: '/rest/enclosure-groups/eg-1',
        )]]);

        $record = iterator_to_array($adapter->pull(), false)[0];

        $this->assertSame('uuid-9', $record->sourceId);
        $this->assertSame('CZ9999', $record->hardwareSerial);
        $this->assertSame('ProLiant BL460c Gen10', $record->hardwareModel);
        $this->assertSame('HPE', $record->manufacturer);
        $this->assertSame('ilo-bay9.example.com', $record->hostname);
        $this->assertSame('10.0.0.9', $record->primaryIp, 'routable IPv4 wins over link-local and IPv6');
        $this->assertSame('/rest/enclosure-groups/eg-1', $record->vendorGroupId);
        $this->assertSame('256.0', $record->extra['oneview_memory_gb']);
        $this->assertSame('On', $record->extra['oneview_power_state']);
    }

    public function test_falls_back_to_resource_name_when_ilo_hostname_is_missing(): void
    {
        $adapter = $this->configuredAdapter();
        $server = $this->server(uuid: 'uuid-3', serial: 'CZ3333', name: 'Encl1, bay 3');
        unset($server['mpHostInfo']['mpHostName'], $server['mpDnsName']);
        $this->fakeAppliance([[$server]]);

        $record = iterator_to_array($adapter->pull(), false)[0];

        $this->assertSame('Encl1, bay 3', $record->hostname);
    }

    public function test_logs_in_with_negotiated_version_and_logs_out(): void
    {
        $adapter = $this->configuredAdapter(['login_domain' => 'corp.example.com']);
        $this->fakeAppliance([[$this->server(uuid: 'uuid-4', serial: 'CZ4444', name: 'x')]]);

        iterator_to_array($adapter->pull(), false);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/rest/login-sessions')
            && $request->header('X-API-Version') === ['6600']
            && $request['userName'] === 'administrator'
            && $request['password'] === 'fake-password'
            && $request['authLoginDomain'] === 'corp.example.com');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/rest/server-hardware')
            && $request->header('Auth') === ['session-abc']);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/rest/login-sessions')
            && $request->header('Auth') === ['session-abc']);
    }

    public function test_logout_failure_does_not_fail_the_pull(): void
    {
        $adapter = $this->configuredAdapter();
        $this->fakeAppliance([[$this->server(uuid: 'uuid-5', serial: 'CZ5555', name: 'x')]], logoutStatus: 500);

        $records = iterator_to_array($adapter->pull(), false);

        $this->assertCount(1, $records);
    }

    public function test_skips_hardware_without_a_uuid(): void
    {
        $adapter = $this->configuredAdapter();
        $broken = $this->server(uuid: '', serial: 'CZ0000', name: 'broken');
        $this->fakeAppliance([[$broken, $this->server(uuid: 'uuid-6', serial: 'CZ6666', name: 'ok')]]);

        $records = iterator_to_array($adapter->pull(), false);

        $this->assertCount(1, $records);
        $this->assertSame('uuid-6', $records[0]->sourceId);
    }

    public function test_fetch_groups_returns_enclosure_group_uris(): void
    {
        $adapter = $this->configuredAdapter();
        Http::fake([
            '*/rest/version' => Http::response(['currentVersion' => 6600]),
            '*/rest/login-sessions' => Http::response(['sessionID' => 'session-abc']),
            '*/rest/enclosure-groups*' => Http::response([
                'members' => [
                    ['uri' => '/rest/enclosure-groups/eg-1', 'name' => 'Production'],
                    ['name' => 'no uri, ignored'],
                ],
            ]),
        ]);

        $this->assertSame(
            [['id' => '/rest/enclosure-groups/eg-1', 'label' => 'Production']],
            $adapter->fetchGroups(),
        );
    }

    public function test_settings_page_renders_the_adapter_schema(): void
    {
        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.adapters.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('HPE OneView', $html);
        $this->assertMatchesRegularExpression('/name="hpe_oneview_password"/', $html);
        $this->assertMatchesRegularExpression('/name="hpe_oneview_skip_tls_verify"/', $html);
    }

    public function test_tls_verification_is_on_unless_explicitly_skipped(): void
    {
        $this->assertTrue($this->clientVerifyOption($this->configuredAdapter()));
        $this->assertFalse($this->clientVerifyOption($this->configuredAdapter(['skip_tls_verify' => '1'])));
    }

    /**
     * @param  array<string, string>  $extraConfig
     */
    private function configuredAdapter(array $extraConfig = []): HpeOneViewAdapter
    {
        $instance = SyncAdapterInstance::where('slug', 'hpe_oneview')->firstOrFail();
        SyncAdapterConfig::put($instance->id, 'url', 'https://oneview.example.com');
        SyncAdapterConfig::put($instance->id, 'username', 'administrator');
        SyncAdapterConfig::put($instance->id, 'password', Crypt::encrypt('fake-password'));
        foreach ($extraConfig as $key => $value) {
            SyncAdapterConfig::put($instance->id, $key, $value);
        }

        return new HpeOneViewAdapter($instance->fresh());
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $pages  members per server-hardware page, in request order
     */
    private function fakeAppliance(array $pages, int $logoutStatus = 204): void
    {
        $hardware = Http::sequence();
        foreach ($pages as $index => $members) {
            $isLast = $index === array_key_last($pages);
            $hardware->push([
                'members' => $members,
                'nextPageUri' => $isLast ? null : '/rest/server-hardware?start='.(($index + 1) * 100),
            ]);
        }

        Http::fake([
            '*/rest/version' => Http::response(['currentVersion' => 6600, 'minimumVersion' => 120]),
            '*/rest/login-sessions' => fn (Request $request) => $request->method() === 'DELETE'
                ? Http::response('', $logoutStatus)
                : Http::response(['sessionID' => 'session-abc']),
            '*/rest/server-hardware*' => $hardware,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function server(string $uuid, string $serial, string $name, ?string $groupUri = null): array
    {
        return [
            'uuid' => $uuid,
            'name' => $name,
            'serialNumber' => $serial,
            'model' => 'ProLiant BL460c Gen10',
            'partNumber' => '863442-B21',
            'powerState' => 'On',
            'state' => 'ProfileApplied',
            'status' => 'OK',
            'processorType' => 'Intel Xeon Gold 6130',
            'processorCount' => 2,
            'processorCoreCount' => 16,
            'memoryMb' => 262144,
            'mpModel' => 'iLO5',
            'mpFirmwareVersion' => '2.72',
            'romVersion' => 'I41 v2.70',
            'position' => 9,
            'serverGroupUri' => $groupUri,
            'mpDnsName' => 'ilo-bay9.example.com',
            'mpHostInfo' => [
                'mpHostName' => 'ilo-bay9.example.com',
                'mpIpAddresses' => [
                    ['type' => 'LinkLocal', 'address' => 'fe80::1'],
                    ['type' => 'SLAAC', 'address' => '2001:db8::9'],
                    ['type' => 'Static', 'address' => '10.0.0.9'],
                ],
            ],
        ];
    }

    private function clientVerifyOption(HpeOneViewAdapter $adapter): bool
    {
        $method = new \ReflectionMethod($adapter, 'client');
        $client = $method->invoke($adapter);
        $property = new \ReflectionProperty($client, 'verifyTls');

        return $property->getValue($client);
    }
}
