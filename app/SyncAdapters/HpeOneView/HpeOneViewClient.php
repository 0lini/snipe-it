<?php

namespace App\SyncAdapters\HpeOneView;

use App\SyncAdapters\Traits\RetriesTransientHttpErrors;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the HPE OneView REST API. Owns session auth and
 * pagination for the endpoints the sync adapter uses and yields raw
 * decoded JSON (normalization is the adapter's job).
 *
 * Auth flow: GET /rest/version (unauthenticated) to learn the newest
 * API version the appliance speaks, POST /rest/login-sessions to trade
 * the credentials for a session id, then every call carries
 * `Auth: <sessionID>` plus `X-API-Version`. The session is closed with
 * DELETE /rest/login-sessions once the caller is done, since appliances
 * cap concurrent sessions.
 *
 * OneView appliances usually run on private networks with a
 * self-signed certificate, so TLS verification can be switched off per
 * adapter instance.
 *
 * API reference: https://developer.hpe.com/platform/hpe-oneview/home/
 */
class HpeOneViewClient
{
    use RetriesTransientHttpErrors;

    private ?string $sessionId = null;

    private ?string $apiVersion = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly ?string $loginDomain = null,
        private readonly bool $verifyTls = true,
    ) {}

    /**
     * Iterate every server hardware resource (blades and rack servers)
     * the appliance manages, one page at a time.
     *
     * @return iterable<array<string, mixed>>
     */
    public function serverHardware(int $count = 100): iterable
    {
        $this->login();

        try {
            $start = 0;
            do {
                $response = $this->authenticated()
                    ->get('/rest/server-hardware', ['start' => $start, 'count' => $count])
                    ->throw()
                    ->throwIfNotJson()
                    ->json();

                $members = is_array($response['members'] ?? null) ? $response['members'] : [];
                foreach ($members as $member) {
                    yield $member;
                }

                $hasMore = ! empty($response['nextPageUri']) && $members !== [];
                $start += $count;
            } while ($hasMore);
        } finally {
            $this->logout();
        }
    }

    /**
     * List every enclosure group. Server hardware references its
     * enclosure group through `serverGroupUri`, so the group URI is
     * the identifier admins map to Snipe-IT companies.
     *
     * @return array<int, array<string, mixed>>
     */
    public function enclosureGroups(int $count = 100): array
    {
        $this->login();

        try {
            $groups = [];
            $start = 0;
            do {
                $response = $this->authenticated()
                    ->get('/rest/enclosure-groups', ['start' => $start, 'count' => $count])
                    ->throw()
                    ->throwIfNotJson()
                    ->json();

                $members = is_array($response['members'] ?? null) ? $response['members'] : [];
                array_push($groups, ...$members);

                $hasMore = ! empty($response['nextPageUri']) && $members !== [];
                $start += $count;
            } while ($hasMore);

            return $groups;
        } finally {
            $this->logout();
        }
    }

    private function login(): void
    {
        $version = $this->request()
            ->get('/rest/version')
            ->throw()
            ->throwIfNotJson()
            ->json('currentVersion');

        if (! is_numeric($version)) {
            throw new \RuntimeException('HPE OneView did not report an API version from /rest/version.');
        }
        $this->apiVersion = (string) (int) $version;

        $body = ['userName' => $this->username, 'password' => $this->password];
        if ($this->loginDomain !== null && $this->loginDomain !== '') {
            $body['authLoginDomain'] = $this->loginDomain;
        }

        $sessionId = $this->request()
            ->withHeaders(['X-API-Version' => $this->apiVersion])
            ->asJson()
            ->post('/rest/login-sessions', $body)
            ->throw()
            ->throwIfNotJson()
            ->json('sessionID');

        if (! is_string($sessionId) || $sessionId === '') {
            throw new \RuntimeException('HPE OneView login did not return a session id.');
        }
        $this->sessionId = $sessionId;
    }

    /**
     * Best effort. A failed logout must never mask pull results or the
     * original error, the appliance expires idle sessions on its own.
     */
    private function logout(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        try {
            $this->authenticated()->delete('/rest/login-sessions');
        } catch (\Throwable) {
            // intentionally ignored
        } finally {
            $this->sessionId = null;
        }
    }

    private function authenticated(): PendingRequest
    {
        return $this->request()->withHeaders([
            'Auth' => (string) $this->sessionId,
            'X-API-Version' => (string) $this->apiVersion,
        ]);
    }

    private function request(): PendingRequest
    {
        return $this->withTransientRetry(
            Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withOptions(['allow_redirects' => false, 'verify' => $this->verifyTls])
                ->acceptJson()
                ->timeout(60)
        );
    }
}
