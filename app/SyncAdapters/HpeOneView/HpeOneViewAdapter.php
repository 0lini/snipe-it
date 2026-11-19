<?php

namespace App\SyncAdapters\HpeOneView;

use App\Models\SyncAdapterConfig;
use App\SyncAdapters\HostInventoryRecord;
use App\SyncAdapters\SyncAdapter;
use Illuminate\Support\Arr;

/**
 * HPE OneView adapter. Pulls the server hardware (blades and rack
 * servers) an HPE OneView appliance manages and normalizes it into
 * HostInventoryRecord objects. Pull only: OneView owns hardware
 * identity, so nothing is pushed back.
 *
 * Auth is a local or directory account on the appliance. The adapter
 * negotiates the newest API version the appliance supports, so no
 * version needs configuring. The account needs read access to server
 * hardware (the built-in "Read only" role is enough).
 *
 * Server hardware is keyed on its `uuid`, which survives bay moves and
 * profile changes. The enclosure group is exposed as the vendor group
 * so admins can map groups to Snipe-IT companies.
 */
class HpeOneViewAdapter extends SyncAdapter
{
    public static function typeLabel(): string
    {
        return 'HPE OneView';
    }

    public static function typeSlug(): string
    {
        return 'hpe_oneview';
    }

    public static function docsUrl(): ?string
    {
        return 'https://developer.hpe.com/platform/hpe-oneview/home/';
    }

    public function baseUrlPlaceholder(): ?string
    {
        return 'https://oneview.example.com';
    }

    public function baseUrlHelp(): ?string
    {
        return trans('admin/settings/sync_adapters.hpe_oneview_base_url_help');
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'username',
                'label' => trans('admin/settings/sync_adapters.label_username'),
                'help' => trans('admin/settings/sync_adapters.hpe_oneview_username_help'),
            ],
            [
                'key' => 'password',
                'label' => trans('admin/settings/sync_adapters.label_password'),
                'secret' => true,
            ],
            [
                'key' => 'login_domain',
                'label' => trans('admin/settings/sync_adapters.hpe_oneview_login_domain_label'),
                'required' => false,
                'help' => trans('admin/settings/sync_adapters.hpe_oneview_login_domain_help'),
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
            'oneview_uuid' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_uuid'],
            'oneview_part_number' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_part_number'],
            'oneview_power_state' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_power_state'],
            'oneview_state' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_state'],
            'oneview_status' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_status'],
            'oneview_processor_type' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_processor_type'],
            'oneview_processor_count' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_processor_count'],
            'oneview_processor_core_count' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_processor_core_count'],
            'oneview_memory_gb' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_memory_gb'],
            'oneview_ilo_model' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_ilo_model'],
            'oneview_ilo_firmware' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_ilo_firmware'],
            'oneview_rom_version' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_rom_version'],
            'oneview_position' => ['label_key' => 'admin/settings/sync_adapters.hpe_oneview_extra_position'],
        ];
    }

    public function supportsGroupScoping(): bool
    {
        return true;
    }

    public function vendorGroupLabel(): string
    {
        return trans('admin/settings/sync_adapters.vendor_group_hpe_oneview_enclosure_group');
    }

    public function fetchGroups(): array
    {
        return array_values(array_filter(array_map(
            function (array $group): ?array {
                $uri = (string) ($group['uri'] ?? '');
                if ($uri === '') {
                    return null;
                }

                return ['id' => $uri, 'label' => (string) ($group['name'] ?? $uri)];
            },
            $this->client()->enclosureGroups(),
        )));
    }

    public function pull(): iterable
    {
        foreach ($this->client()->serverHardware() as $server) {
            $uuid = Arr::get($server, 'uuid');
            if (! is_string($uuid) || $uuid === '') {
                continue;
            }

            yield $this->normalize($server);
        }
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function normalize(array $server): HostInventoryRecord
    {
        $serial = Arr::get($server, 'serialNumber');
        $hostname = Arr::get($server, 'mpHostInfo.mpHostName')
            ?: Arr::get($server, 'mpDnsName')
            ?: Arr::get($server, 'name');
        $groupUri = Arr::get($server, 'serverGroupUri');

        return new HostInventoryRecord(
            sourceKey: $this->name(),
            sourceId: (string) Arr::get($server, 'uuid'),
            hostname: $this->stringOrNull($hostname),
            hardwareSerial: $this->stringOrNull($serial),
            hardwareModel: $this->stringOrNull(Arr::get($server, 'model')),
            manufacturer: 'HPE',
            primaryMac: null,
            primaryIp: $this->managementIp(Arr::get($server, 'mpHostInfo.mpIpAddresses', [])),
            os: null,
            osVersion: null,
            lastSeen: null,
            assetTag: null,
            assignedUserEmail: null,
            assignedUserName: null,
            vendorGroupId: is_string($groupUri) && $groupUri !== '' ? $groupUri : null,
            extra: [
                'oneview_uuid' => Arr::get($server, 'uuid'),
                'oneview_part_number' => Arr::get($server, 'partNumber'),
                'oneview_power_state' => Arr::get($server, 'powerState'),
                'oneview_state' => Arr::get($server, 'state'),
                'oneview_status' => Arr::get($server, 'status'),
                'oneview_processor_type' => Arr::get($server, 'processorType'),
                'oneview_processor_count' => Arr::get($server, 'processorCount'),
                'oneview_processor_core_count' => Arr::get($server, 'processorCoreCount'),
                'oneview_memory_gb' => $this->mbToGb(Arr::get($server, 'memoryMb')),
                'oneview_ilo_model' => Arr::get($server, 'mpModel'),
                'oneview_ilo_firmware' => Arr::get($server, 'mpFirmwareVersion'),
                'oneview_rom_version' => Arr::get($server, 'romVersion'),
                'oneview_position' => Arr::get($server, 'position'),
            ],
        );
    }

    /**
     * Pick the iLO address worth showing as the host's IP. Prefers a
     * routable IPv4 address over IPv6 and skips link-local entries
     * (169.254.x.x / fe80::) unless nothing else is available.
     *
     * @param  mixed  $addresses  `mpHostInfo.mpIpAddresses`, entries shaped {type, address}
     */
    private function managementIp(mixed $addresses): ?string
    {
        if (! is_array($addresses)) {
            return null;
        }

        $routableV6 = null;
        $linkLocal = null;
        foreach ($addresses as $entry) {
            $address = is_array($entry) ? ($entry['address'] ?? null) : null;
            if (! is_string($address) || $address === '') {
                continue;
            }

            $isLinkLocal = ($entry['type'] ?? null) === 'LinkLocal'
                || str_starts_with($address, '169.254.')
                || str_starts_with(strtolower($address), 'fe80:');
            if ($isLinkLocal) {
                $linkLocal ??= $address;

                continue;
            }
            if (str_contains($address, '.')) {
                return $address;
            }
            $routableV6 ??= $address;
        }

        return $routableV6 ?? $linkLocal;
    }

    private function mbToGb(mixed $megabytes): ?string
    {
        if (! is_numeric($megabytes) || (int) $megabytes <= 0) {
            return null;
        }

        return number_format((int) $megabytes / 1024, 1, '.', '');
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function client(): HpeOneViewClient
    {
        return new HpeOneViewClient(
            baseUrl: $this->url(),
            username: $this->credential('username'),
            password: $this->credential('password'),
            loginDomain: SyncAdapterConfig::get($this->instance->id, 'login_domain'),
            verifyTls: SyncAdapterConfig::get($this->instance->id, 'skip_tls_verify') !== '1',
        );
    }
}
