<?php

namespace App\SyncAdapters\DellOme;

use App\Models\SyncAdapterConfig;
use App\SyncAdapters\HostInventoryRecord;
use App\SyncAdapters\SyncAdapter;
use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * Dell OpenManage Enterprise adapter. Pulls the devices (servers,
 * chassis, storage, network gear) an OME appliance manages and
 * normalizes them into HostInventoryRecord objects. Pull only: OME
 * owns hardware identity, so nothing is pushed back.
 *
 * Auth is an OME account (the built-in "Viewer" role is enough). The
 * device list does not carry group membership, so this adapter has no
 * group-to-company mapping.
 *
 * Devices are keyed on OME's numeric device `Id`. The Dell service tag
 * is the hardware serial.
 */
class DellOmeAdapter extends SyncAdapter
{
    private const TYPE_LABELS = [
        1000 => 'Server',
        2000 => 'Chassis',
        3000 => 'Storage',
        4000 => 'Network',
    ];

    private const POWER_STATE_LABELS = [
        17 => 'On',
        18 => 'Off',
        20 => 'Powering On',
        21 => 'Powering Off',
    ];

    private const STATUS_LABELS = [
        1000 => 'Normal',
        2000 => 'Unknown',
        3000 => 'Warning',
        4000 => 'Critical',
        5000 => 'No Status',
    ];

    public static function typeLabel(): string
    {
        return 'Dell OpenManage Enterprise';
    }

    public static function typeSlug(): string
    {
        return 'dell_ome';
    }

    public static function docsUrl(): ?string
    {
        return 'https://developer.dell.com/apis/2978/versions/4.0';
    }

    public function baseUrlPlaceholder(): ?string
    {
        return 'https://ome.example.com';
    }

    public function baseUrlHelp(): ?string
    {
        return trans('admin/settings/sync_adapters.dell_ome_base_url_help');
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'username',
                'label' => trans('admin/settings/sync_adapters.label_username'),
                'help' => trans('admin/settings/sync_adapters.dell_ome_username_help'),
            ],
            [
                'key' => 'password',
                'label' => trans('admin/settings/sync_adapters.label_password'),
                'secret' => true,
            ],
            [
                'key' => 'device_type',
                'label' => trans('admin/settings/sync_adapters.dell_ome_device_type_label'),
                'type' => 'select',
                'required' => false,
                'options' => [
                    'all' => trans('admin/settings/sync_adapters.dell_ome_device_type_all'),
                    'server' => trans('admin/settings/sync_adapters.dell_ome_device_type_server'),
                    'chassis' => trans('admin/settings/sync_adapters.dell_ome_device_type_chassis'),
                    'storage' => trans('admin/settings/sync_adapters.dell_ome_device_type_storage'),
                    'network' => trans('admin/settings/sync_adapters.dell_ome_device_type_network'),
                ],
                'help' => trans('admin/settings/sync_adapters.dell_ome_device_type_help'),
            ],
            [
                'key' => 'skip_tls_verify',
                'label' => trans('admin/settings/sync_adapters.label_skip_tls_verify'),
                'type' => 'checkbox',
                'required' => false,
                'help' => trans('admin/settings/sync_adapters.label_skip_tls_verify_help'),
            ],
        ];
    }

    public function extraFields(): array
    {
        return [
            'ome_device_id' => ['label_key' => 'admin/settings/sync_adapters.dell_ome_extra_device_id'],
            'ome_device_type' => ['label_key' => 'admin/settings/sync_adapters.dell_ome_extra_device_type'],
            'ome_power_state' => ['label_key' => 'admin/settings/sync_adapters.dell_ome_extra_power_state'],
            'ome_status' => ['label_key' => 'admin/settings/sync_adapters.dell_ome_extra_status'],
            'ome_connected' => ['label_key' => 'admin/settings/sync_adapters.dell_ome_extra_connected', 'type' => 'boolean'],
            'ome_chassis_service_tag' => ['label_key' => 'admin/settings/sync_adapters.dell_ome_extra_chassis_service_tag'],
            'ome_management_firmware' => ['label_key' => 'admin/settings/sync_adapters.dell_ome_extra_management_firmware'],
            'ome_last_inventory' => ['label_key' => 'admin/settings/sync_adapters.dell_ome_extra_last_inventory'],
        ];
    }

    public function pull(): iterable
    {
        foreach ($this->client()->devices() as $device) {
            $id = Arr::get($device, 'Id');
            if (! is_scalar($id) || (string) $id === '') {
                continue;
            }

            yield $this->normalize($device);
        }
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function normalize(array $device): HostInventoryRecord
    {
        $management = $this->primaryManagement(Arr::get($device, 'DeviceManagement', []));
        $serial = Arr::get($device, 'DeviceServiceTag') ?: Arr::get($device, 'Identifier');
        $hostname = Arr::get($device, 'DeviceName') ?: Arr::get($management, 'DnsName');
        $lastSeen = Arr::get($device, 'LastStatusTime') ?: Arr::get($device, 'LastInventoryTime');
        $typeCode = Arr::get($device, 'Type');
        $connection = Arr::get($device, 'ConnectionState');

        return new HostInventoryRecord(
            sourceKey: $this->name(),
            sourceId: (string) Arr::get($device, 'Id'),
            hostname: $this->stringOrNull($hostname),
            hardwareSerial: $this->stringOrNull($serial),
            hardwareModel: $this->stringOrNull(Arr::get($device, 'Model')),
            manufacturer: 'Dell',
            primaryMac: $this->stringOrNull(Arr::get($management, 'MacAddress')),
            primaryIp: $this->stringOrNull(Arr::get($management, 'NetworkAddress')),
            os: null,
            osVersion: null,
            lastSeen: $this->parseTimestamp($lastSeen),
            assetTag: null,
            assignedUserEmail: null,
            assignedUserName: null,
            vendorGroupId: null,
            extra: [
                'ome_device_id' => (string) Arr::get($device, 'Id'),
                'ome_device_type' => self::TYPE_LABELS[$typeCode] ?? null,
                'ome_power_state' => self::POWER_STATE_LABELS[Arr::get($device, 'PowerState')] ?? null,
                'ome_status' => self::STATUS_LABELS[Arr::get($device, 'Status')] ?? null,
                'ome_connected' => is_bool($connection) ? $connection : null,
                'ome_chassis_service_tag' => Arr::get($device, 'ChassisServiceTag'),
                'ome_management_firmware' => Arr::get($management, 'ManagementProfile.0.Version'),
                'ome_last_inventory' => Arr::get($device, 'LastInventoryTime'),
            ],
        );
    }

    /**
     * Pick the management endpoint (iDRAC, chassis controller, switch
     * management port) that carries an address. A device can list
     * several; the first with a network address wins.
     *
     * @param  mixed  $entries  the device's `DeviceManagement` array
     * @return array<string, mixed>
     */
    private function primaryManagement(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $first = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $first = $first ?: $entry;
            if (is_string($entry['NetworkAddress'] ?? null) && $entry['NetworkAddress'] !== '') {
                return $entry;
            }
        }

        return $first;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function client(): DellOmeClient
    {
        return new DellOmeClient(
            baseUrl: $this->url(),
            username: $this->credential('username'),
            password: $this->credential('password'),
            deviceType: SyncAdapterConfig::get($this->instance->id, 'device_type') ?? 'all',
            verifyTls: SyncAdapterConfig::get($this->instance->id, 'skip_tls_verify') !== '1',
        );
    }
}
