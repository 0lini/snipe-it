<?php

namespace App\SyncAdapters\NetBox;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the NetBox REST API. Owns auth + pagination for
 * the endpoints the sync adapter uses. Yields raw decoded JSON, no
 * normalization (that's the adapter's job).
 *
 * Auth model: NetBox API token from the user's profile page. Legacy
 * v1 tokens use `Authorization: Token <key>`; NetBox 4.5+ v2 tokens
 * (`nbt_<key>.<secret>`) use `Authorization: Bearer <full-token>`.
 * The client picks the header form from the token shape so admins
 * can paste either without a separate "token version" setting.
 *
 * Pagination: NetBox list endpoints return `{count, next, previous,
 * results}` and accept `limit` / `offset` query params. We walk
 * offset pages until a short page or count is reached.
 *
 * NetBox REST API reference:
 * https://netboxlabs.com/docs/netbox/integrations/rest-api/
 */
class NetBoxClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    /**
     * Iterate every DCIM device in the NetBox instance.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function devices(int $limit = 100): iterable
    {
        yield from $this->paginate('/api/dcim/devices/', $limit);
    }

    /**
     * List every site. Used by the adapter's fetchGroups() so admins
     * can map NetBox sites to Snipe-IT companies.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sites(int $limit = 100): array
    {
        $sites = [];
        foreach ($this->paginate('/api/dcim/sites/', $limit) as $site) {
            $sites[] = $site;
        }

        return $sites;
    }

    /**
     * PATCH a device's writable metadata (asset_tag, comments, etc.).
     * Fields the caller doesn't include stay untouched.
     *
     * @param  array<string, mixed>  $fields
     */
    public function updateDevice(string $deviceId, array $fields): void
    {
        $this->request()
            ->patch('/api/dcim/devices/'.$deviceId.'/', $fields)
            ->throw();
    }

    /**
     * Offset-paginated walk of a NetBox list endpoint.
     *
     * @return iterable<int, array<string, mixed>>
     */
    private function paginate(string $path, int $limit): iterable
    {
        $offset = 0;

        do {
            $response = $this->request()
                ->get($path, [
                    'limit' => $limit,
                    'offset' => $offset,
                ])
                ->throw()
                ->json();

            $results = $response['results'] ?? (is_array($response) ? $response : []);
            foreach ($results as $row) {
                yield $row;
            }

            $total = $response['count'] ?? null;
            $seenSoFar = $offset + count($results);
            $done = count($results) < $limit
                || ($total !== null && $seenSoFar >= $total);
            $offset += $limit;
        } while (! $done);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders(['Authorization' => $this->authorizationHeader()])
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * Choose Token vs Bearer based on the pasted credential. v2 tokens
     * always start with the `nbt_` prefix; everything else (including
     * classic hex keys) uses the legacy Token scheme NetBox has
     * accepted since the beginning.
     */
    private function authorizationHeader(): string
    {
        if (str_starts_with($this->token, 'nbt_')) {
            return 'Bearer '.$this->token;
        }

        return 'Token '.$this->token;
    }
}
