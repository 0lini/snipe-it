<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * OpenID Connect relying party: Authorization Code flow with PKCE,
 * state and nonce, and ID token signature verification against the
 * provider's published JWKS.
 */
class Oidc
{
    public const AUTH_SESSION_KEY = '_oidcAuth';

    public const ID_TOKEN_SESSION_KEY = '_oidcIdToken';

    public const DEFAULT_SCOPES = 'openid profile email';

    public const DEFAULT_USERNAME_CLAIM = 'preferred_username';

    /**
     * Seconds of clock skew tolerated when checking exp/iat/nbf.
     */
    public const LEEWAY = 60;

    private const CACHE_TTL = 3600;

    /**
     * Whether OIDC login is enabled and minimally configured.
     */
    public function isEnabled(): bool
    {
        $setting = Setting::getSettings();

        return $setting
            && $setting->oidc_enabled == '1'
            && filled($setting->oidc_issuer)
            && filled($setting->oidc_client_id);
    }

    /**
     * Label shown on the login button.
     */
    public function displayName(): string
    {
        return Setting::getSettings()->oidc_display_name ?: trans('admin/settings/general.oidc');
    }

    /**
     * Callback URL registered at the provider.
     */
    public function redirectUri(): string
    {
        return route('oidc.callback');
    }

    /**
     * Build the authorization URL and remember state, nonce and PKCE verifier in the session.
     */
    public function getAuthorizationUrl(): string
    {
        $setting = Setting::getSettings();
        $state = Str::random(40);
        $nonce = Str::random(40);
        $codeVerifier = Str::random(64);

        session()->put(self::AUTH_SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $setting->oidc_client_id,
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->scopes(),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64UrlEncode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        $endpoint = $this->discovery()['authorization_endpoint'];

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Validate the callback, redeem the code and return the verified ID token claims.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when any part of the exchange or validation fails
     */
    public function handleCallback(?string $code, ?string $state): array
    {
        $pending = session()->pull(self::AUTH_SESSION_KEY);

        if (! is_array($pending) || empty($pending['state']) || ! is_string($state) || ! hash_equals($pending['state'], $state)) {
            throw new RuntimeException('OIDC state mismatch.');
        }

        if (! is_string($code) || $code === '') {
            throw new RuntimeException('OIDC callback is missing the authorization code.');
        }

        $setting = Setting::getSettings();
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $setting->oidc_client_id,
            'code_verifier' => $pending['code_verifier'],
        ];

        $request = Http::asForm()->acceptJson()->timeout(15);
        if (($secret = $this->clientSecret()) !== null) {
            $request = $request->withBasicAuth(urlencode($setting->oidc_client_id), urlencode($secret));
        }

        $response = $request->post($this->discovery()['token_endpoint'], $params);

        if (! $response->successful() || ! is_string($response->json('id_token'))) {
            throw new RuntimeException('OIDC token endpoint returned '.$response->status().': '.Str::limit($response->body(), 300));
        }

        $idToken = $response->json('id_token');
        $claims = $this->verifyIdToken($idToken, $pending['nonce']);

        session()->put(self::ID_TOKEN_SESSION_KEY, $idToken);

        return $claims;
    }

    /**
     * Verify signature, issuer, audience, expiry and nonce of an ID token.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function verifyIdToken(string $idToken, string $expectedNonce): array
    {
        JWT::$leeway = self::LEEWAY;

        try {
            $claims = (array) JWT::decode($idToken, $this->keySet());
        } catch (ExpiredException|BeforeValidException $e) {
            throw new RuntimeException('OIDC ID token is not currently valid: '.$e->getMessage());
        } catch (Throwable $e) {
            // The provider may have rotated its keys since we cached them.
            Cache::forget($this->cacheKey('jwks'));
            try {
                $claims = (array) JWT::decode($idToken, $this->keySet());
            } catch (Throwable $retryException) {
                throw new RuntimeException('OIDC ID token signature is invalid: '.$retryException->getMessage());
            }
        }

        $setting = Setting::getSettings();
        $clientId = $setting->oidc_client_id;

        if (($claims['iss'] ?? null) !== $this->discovery()['issuer']) {
            throw new RuntimeException('OIDC ID token issuer mismatch.');
        }

        $audiences = (array) ($claims['aud'] ?? []);
        if (! in_array($clientId, $audiences, true)) {
            throw new RuntimeException('OIDC ID token audience mismatch.');
        }

        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $clientId) {
            throw new RuntimeException('OIDC ID token authorized party mismatch.');
        }

        if (! isset($claims['exp'])) {
            throw new RuntimeException('OIDC ID token has no expiry.');
        }

        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($expectedNonce, $claims['nonce'])) {
            throw new RuntimeException('OIDC ID token nonce mismatch.');
        }

        return $claims;
    }

    /**
     * Find the Snipe-IT user for verified claims, creating one when allowed.
     *
     * @param  array<string, mixed>  $claims
     */
    public function findOrCreateUser(array $claims): ?User
    {
        $username = $this->usernameFromClaims($claims);

        if ($username === null) {
            Log::warning('OIDC login rejected: the configured username claim "'.$this->usernameClaim().'" is missing from the ID token.');

            return null;
        }

        $user = User::where('username', '=', $username)
            ->whereNull('deleted_at')
            ->where('activated', '=', '1')
            ->first();

        $user = User::verifyExactUsernameMatch($user, $username);

        if (is_null($user) && Setting::getSettings()->oidc_auto_create_users) {
            $user = $this->createUserFromClaims($username, $claims);
        }

        if (is_null($user)) {
            Log::debug("OIDC user '$username' could not be found in database.");
        }

        return $user;
    }

    /**
     * Username from the configured claim, or null when absent or empty.
     *
     * @param  array<string, mixed>  $claims
     */
    public function usernameFromClaims(array $claims): ?string
    {
        $value = $claims[$this->usernameClaim()] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * RP-initiated logout URL at the provider, or null when unsupported or disabled.
     */
    public function getLogoutUrl(?string $idToken): ?string
    {
        if (! $this->isEnabled() || Setting::getSettings()->oidc_slo != '1' || empty($idToken)) {
            return null;
        }

        try {
            $endpoint = $this->discovery()['end_session_endpoint'] ?? null;
        } catch (Throwable $e) {
            Log::warning('OIDC discovery failed during logout: '.$e->getMessage());

            return null;
        }

        if (! is_string($endpoint) || ! $this->isHttpsOrLocal($endpoint)) {
            return null;
        }

        $query = http_build_query([
            'id_token_hint' => $idToken,
            'client_id' => Setting::getSettings()->oidc_client_id,
            'post_logout_redirect_uri' => route('login'),
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Provider metadata from /.well-known/openid-configuration.
     *
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string, end_session_endpoint?: string}
     *
     * @throws RuntimeException
     */
    public function discovery(): array
    {
        $issuer = rtrim((string) Setting::getSettings()->oidc_issuer, '/');

        $metadata = Cache::remember($this->cacheKey('discovery'), self::CACHE_TTL, function () use ($issuer) {
            $response = Http::acceptJson()->timeout(15)->get($issuer.'/.well-known/openid-configuration');

            if (! $response->successful() || ! is_array($response->json())) {
                throw new RuntimeException('OIDC discovery failed with HTTP '.$response->status().'.');
            }

            return $response->json();
        });

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
            if (empty($metadata[$required]) || ! is_string($metadata[$required])) {
                Cache::forget($this->cacheKey('discovery'));
                throw new RuntimeException("OIDC discovery document is missing '$required'.");
            }
        }

        if (rtrim($metadata['issuer'], '/') !== $issuer) {
            Cache::forget($this->cacheKey('discovery'));
            throw new RuntimeException('OIDC discovery issuer does not match the configured issuer.');
        }

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $endpoint) {
            if (! $this->isHttpsOrLocal($metadata[$endpoint])) {
                Cache::forget($this->cacheKey('discovery'));
                throw new RuntimeException("OIDC $endpoint must use https.");
            }
        }

        return $metadata;
    }

    /**
     * Forget cached discovery metadata and signing keys (e.g. after the issuer changes).
     */
    public function clearCache(): void
    {
        Cache::forget($this->cacheKey('discovery'));
        Cache::forget($this->cacheKey('jwks'));
    }

    /**
     * Parsed signing keys from the provider's JWKS.
     *
     * @return array<string, \Firebase\JWT\Key>
     */
    private function keySet(): array
    {
        $jwks = Cache::remember($this->cacheKey('jwks'), self::CACHE_TTL, function () {
            $response = Http::acceptJson()->timeout(15)->get($this->discovery()['jwks_uri']);

            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new RuntimeException('OIDC JWKS fetch failed with HTTP '.$response->status().'.');
            }

            return $response->json();
        });

        // Encryption keys can't verify signatures and may lack an alg; skip them.
        $jwks['keys'] = array_values(array_filter($jwks['keys'], fn ($key) => ($key['use'] ?? 'sig') === 'sig'));

        return JWK::parseKeySet($jwks, 'RS256');
    }

    private function scopes(): string
    {
        $scopes = preg_split('/\s+/', trim((string) Setting::getSettings()->oidc_scopes), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($scopes)) {
            return self::DEFAULT_SCOPES;
        }

        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return implode(' ', array_unique($scopes));
    }

    private function usernameClaim(): string
    {
        return Setting::getSettings()->oidc_username_claim ?: self::DEFAULT_USERNAME_CLAIM;
    }

    private function clientSecret(): ?string
    {
        $encrypted = Setting::getSettings()->oidc_client_secret;

        if (empty($encrypted)) {
            return null;
        }

        try {
            return Crypt::decrypt($encrypted);
        } catch (DecryptException $e) {
            Log::warning('OIDC client secret could not be decrypted; check APP_KEY.');

            return null;
        }
    }

    /**
     * Create an activated, permissionless user from the ID token claims.
     *
     * Never re-creates a username held by a deactivated or deleted user, so
     * an admin's deactivation or deletion still blocks OIDC login.
     *
     * @param  array<string, mixed>  $claims
     */
    private function createUserFromClaims(string $username, array $claims): ?User
    {
        if (User::withTrashed()->where('username', '=', $username)->exists()) {
            return null;
        }

        $user = new User([
            'username' => $username,
            'first_name' => $this->stringClaim($claims, 'given_name') ?? $this->stringClaim($claims, 'name') ?? $username,
            'last_name' => $this->stringClaim($claims, 'family_name'),
            'email' => $this->stringClaim($claims, 'email'),
            'activated' => 1,
        ]);
        $user->password = $user->noPassword();

        if (! $user->save()) {
            Log::warning("OIDC user '$username' could not be created: ".$user->getErrors()->toJson());

            return null;
        }

        Log::info("Created OIDC user '$username' on first login.");

        return $user;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function stringClaim(array $claims, string $name): ?string
    {
        $value = $claims[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isHttpsOrLocal(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
    }

    private function cacheKey(string $suffix): string
    {
        return 'oidc_'.$suffix.'_'.md5((string) Setting::getSettings()->oidc_issuer);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
