<?php

namespace App\SyncAdapters\NetBox;

use App\Models\Asset;
use App\SyncAdapters\HostInventoryRecord;
use App\SyncAdapters\PushableAdapter;
use App\SyncAdapters\Support\ConfigurableAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * NetBox adapter. Pulls DCIM device inventory from a NetBox instance
 * via the REST API and normalizes it into HostInventoryRecord objects.
 * Also pushes Snipe-IT-authoritative fields back to NetBox (asset_tag
 * today, plus composed notes into NetBox's freeform `comments` field)
 * when the admin marks the mapping row as 'push'.
 *
 * NetBox is a source of truth for datacenter / network hardware rather
 * than an MDM, so last-seen heartbeats are not populated. Primary IP
 * comes from the device's designated primary_ip (IPv4 or IPv6); MAC
 * addresses live on interfaces and are not pulled here to avoid N+1
 * detail requests.
 *
 * Site scoping: NetBox sites map to Snipe-IT companies via the shared
 * group-to-company UI when Full Multiple Companies Support is on.
 */
class NetBoxAdapter extends ConfigurableAdapter implements PushableAdapter
{
    public static function typeLabel(): string
    {
        return 'NetBox';
    }

    public function baseUrlPlaceholder(): ?string
    {
        return 'https://netbox.example.com';
    }

    public function credentialSchema(): array
    {
        return [
            [
                'key' => 'token',
                'label' => 'API Token',
                'secret' => true,
                'help' => trans('admin/settings/sync_adapters.netbox_token_help'),
            ],
        ];
    }

    public function extraFields(): array
    {
        return [
            'netbox_site' => ['label_key' => 'admin/settings/sync_adapters.extra_site'],
            'netbox_tenant' => ['label_key' => 'admin/settings/sync_adapters.extra_tenant'],
            'netbox_role' => ['label_key' => 'admin/settings/sync_adapters.extra_role'],
            'netbox_status' => ['label_key' => 'admin/settings/sync_adapters.extra_status'],
            'netbox_rack' => ['label_key' => 'admin/settings/sync_adapters.extra_rack'],
            'netbox_location' => ['label_key' => 'admin/settings/sync_adapters.extra_location'],
            'netbox_device_type' => ['label_key' => 'admin/settings/sync_adapters.extra_device_type'],
        ];
    }

    public function supportsGroupScoping(): bool
    {
        return true;
    }

    public function vendorGroupLabel(): string
    {
        return 'NetBox Site';
    }

    public function fetchGroups(): array
    {
        $client = new NetBoxClient(baseUrl: $this->url(), token: $this->credential('token'));

        return array_map(
            fn (array $site) => [
                'id' => (string) ($site['id'] ?? ''),
                'label' => (string) ($site['name'] ?? $site['display'] ?? $site['id'] ?? '?'),
            ],
            $client->sites(),
        );
    }

    public function pull(): iterable
    {
        $client = new NetBoxClient(baseUrl: $this->url(), token: $this->credential('token'));

        foreach ($client->devices() as $device) {
            yield $this->normalize($device);
        }
    }

    /**
     * Convert a NetBox device payload into the normalized record shape.
     * NetBox's numeric `id` is stable per device and is what we key
     * asset_external_sources on.
     *
     * @param  array<string, mixed>  $device
     */
    private function normalize(array $device): HostInventoryRecord
    {
        $status = Arr::get($device, 'status.value')
            ?? Arr::get($device, 'status.label')
            ?? Arr::get($device, 'status');

        return new HostInventoryRecord(
            sourceKey: $this->name(),
            sourceId: (string) Arr::get($device, 'id'),
            hostname: Arr::get($device, 'name')
                ?? Arr::get($device, 'display'),
            hardwareSerial: Arr::get($device, 'serial') ?: null,
            hardwareModel: Arr::get($device, 'device_type.model')
                ?? Arr::get($device, 'device_type.display'),
            manufacturer: Arr::get($device, 'device_type.manufacturer.name')
                ?? Arr::get($device, 'device_type.manufacturer.display'),
            primaryMac: null,
            primaryIp: $this->primaryIpAddress($device),
            os: Arr::get($device, 'platform.name')
                ?? Arr::get($device, 'platform.display'),
            osVersion: null,
            lastSeen: null,
            assetTag: Arr::get($device, 'asset_tag') ?: null,
            vendorGroupId: Arr::has($device, 'site.id')
                ? (string) Arr::get($device, 'site.id')
                : null,
            extra: [
                'netbox_site' => Arr::get($device, 'site.name')
                    ?? Arr::get($device, 'site.display'),
                'netbox_tenant' => Arr::get($device, 'tenant.name')
                    ?? Arr::get($device, 'tenant.display'),
                'netbox_role' => Arr::get($device, 'role.name')
                    ?? Arr::get($device, 'role.display')
                    ?? Arr::get($device, 'device_role.name'),
                'netbox_status' => is_scalar($status) ? (string) $status : null,
                'netbox_rack' => Arr::get($device, 'rack.name')
                    ?? Arr::get($device, 'rack.display'),
                'netbox_location' => Arr::get($device, 'location.name')
                    ?? Arr::get($device, 'location.display'),
                'netbox_device_type' => Arr::get($device, 'device_type.display')
                    ?? Arr::get($device, 'device_type.model'),
            ],
        );
    }

    /**
     * Prefer primary_ip (NetBox's designated management address), then
     * fall back to the IPv4 / IPv6 slots. Strip the CIDR suffix so the
     * shared primaryIp column stores a bare address.
     *
     * @param  array<string, mixed>  $device
     */
    private function primaryIpAddress(array $device): ?string
    {
        $address = Arr::get($device, 'primary_ip.address')
            ?? Arr::get($device, 'primary_ip4.address')
            ?? Arr::get($device, 'primary_ip6.address');

        if (! is_string($address) || $address === '') {
            return null;
        }

        return explode('/', $address, 2)[0];
    }

    /**
     * NetBox accepts writes on any API token with write enabled and
     * dcim.change_device permission, so push is always available for a
     * configured instance.
     */
    public function canPush(): bool
    {
        return true;
    }

    /**
     * NetBox devices carry a freeform `comments` field that admins
     * commonly use for composed asset info from Snipe-IT.
     */
    public function notesFieldTarget(): ?string
    {
        return 'comments';
    }

    /**
     * Push Snipe-IT-authoritative fields to NetBox. Filters the
     * asset's fields down to those marked 'push' in the mapping table
     * for this instance, serializes them into NetBox's field-name
     * shape, and PATCHes the device.
     *
     * @param  array<int, string>  $changedFields
     */
    public function push(Asset $asset, array $changedFields = []): void
    {
        $externalSource = $this->pushPrologue($asset, $changedFields);
        if ($externalSource === null) {
            return;
        }

        $payload = [];
        foreach ($this->pushDirectedFields() as $field) {
            $mapped = self::sourceFieldToNetBoxField($field);
            if ($mapped === null) {
                continue;
            }

            $value = $this->assetValueForSourceField($asset, $field);
            if ($value === null) {
                continue;
            }

            $payload[$mapped] = $value;
        }

        $composed = $this->composeNotesForPush($asset);
        if ($composed !== '') {
            $payload[$this->effectiveNotesTarget()] = $composed;
        }

        if ($payload === []) {
            return;
        }

        if ($this->isPushDryRun()) {
            Log::channel('sync-adapters')->info(sprintf(
                '%s push [dry-run]: would PATCH NetBox device %s with %s',
                $this->name(),
                $externalSource->external_id,
                json_encode($payload, JSON_UNESCAPED_SLASHES),
            ));

            return;
        }

        $client = new NetBoxClient(baseUrl: $this->url(), token: $this->credential('token'));
        $client->updateDevice($externalSource->external_id, $payload);

        Log::channel('sync-adapters')->info(sprintf(
            '%s push: updated NetBox device %s with fields [%s]',
            $this->name(),
            $externalSource->external_id,
            implode(', ', array_keys($payload)),
        ));
    }

    /**
     * Map a source-field name to NetBox's device-endpoint form field
     * name. Returns null when there's no writable equivalent.
     */
    private static function sourceFieldToNetBoxField(string $field): ?string
    {
        return match ($field) {
            'asset_tag' => 'asset_tag',
            default => null,
        };
    }

    private function assetValueForSourceField(Asset $asset, string $field): mixed
    {
        return match ($field) {
            'asset_tag' => $asset->asset_tag,
            default => null,
        };
    }
}
