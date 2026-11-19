<?php

namespace App\SyncAdapters\DellOme;

use App\SyncAdapters\Traits\RetriesTransientHttpErrors;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Dell OpenManage Enterprise (OME) REST API.
 * Owns auth and OData paging for the device list the sync adapter
 * uses and yields raw decoded JSON (normalization is the adapter's
 * job).
 *
 * Auth is HTTP Basic on every request, which OME supports for its
 * REST API. OME appliances usually run on private networks with a
 * self-signed certificate, so TLS verification can be switched off
 * per adapter instance.
 *
 * API reference: https://developer.dell.com/apis/2978/versions/4.0
 */
class DellOmeClient
{
    use RetriesTransientHttpErrors;

    /**
     * OME's numeric device type codes, keyed by the adapter's
     * `device_type` setting. 'all' applies no filter.
     */
    public const DEVICE_TYPE_CODES = [
        'server' => 1000,
        'chassis' => 2000,
        'storage' => 3000,
        'network' => 4000,
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $deviceType = 'all',
        private readonly bool $verifyTls = true,
    ) {}

    /**
     * Iterate every device OME manages, one page at a time. OME wraps
     * rows in an OData envelope (`value`, `@odata.count`,
     * `@odata.nextLink`); paging is driven by $top/$skip rather than
     * by following nextLink so the host never comes from the response.
     *
     * @return iterable<array<string, mixed>>
     */
    public function devices(int $top = 100): iterable
    {
        $skip = 0;

        do {
            $query = ['$top' => $top, '$skip' => $skip];
            $typeCode = self::DEVICE_TYPE_CODES[$this->deviceType] ?? null;
            if ($typeCode !== null) {
                $query['$filter'] = 'Type eq '.$typeCode;
            }

            $response = $this->request()
                ->get('/api/DeviceService/Devices', $query)
                ->throw()
                ->throwIfNotJson()
                ->json();

            $rows = is_array($response['value'] ?? null) ? $response['value'] : [];
            foreach ($rows as $row) {
                yield $row;
            }

            $hasMore = ! empty($response['@odata.nextLink']) && $rows !== [];
            $skip += $top;
        } while ($hasMore);
    }

    private function request(): PendingRequest
    {
        return $this->withTransientRetry(
            Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withOptions(['allow_redirects' => false, 'verify' => $this->verifyTls])
                ->withBasicAuth($this->username, $this->password)
                ->acceptJson()
                ->timeout(60)
        );
    }
}
