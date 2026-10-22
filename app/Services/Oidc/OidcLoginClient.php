<?php

namespace App\Services\Oidc;

use App\Exceptions\OidcLoginException;
use App\Models\Setting;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Relying-party side of the OIDC authorization code flow used for web UI
 * login. Configuration lives in the settings table (Admin > Settings > OIDC)
 * so it can be managed like the Google and SAML logins; the API bearer guard
 * in config/oidc.php is separate and unaffected.
 *
 * The flow uses state, nonce and PKCE (S256). The ID token is verified against
 * the provider's JWKS (asymmetric keys only), and its iss/aud/azp/nonce/exp
 * claims are checked before any claim is trusted.
 */
class OidcLoginClient
{
    public const DEFAULT_SCOPES = 'openid profile email';

    public const DEFAULT_USERNAME_CLAIM = 'preferred_username';

    public function __construct(protected Setting $setting) {}

    public static function fromSettings(): self
    {
        return new self(Setting::getSettings());
    }

    public function isEnabled(): bool
    {
        return $this->setting->oidc_login == '1'
            && ! empty($this->setting->oidc_issuer)
            && ! empty($this->setting->oidc_client_id)
            && ! empty($this->setting->oidc_client_secret);
    }

    public function redirectUri(): string
    {
        return route('oidc.callback');
    }

    public function usernameClaim(): string
    {
        return $this->setting->oidc_username_claim ?: self::DEFAULT_USERNAME_CLAIM;
    }

    /**
     * The provider's discovery document, cached for an hour. The advertised
     * issuer must match the configured issuer (OIDC Discovery 4.3).
     *
     * @return array<string, mixed>
     *
     * @throws OidcLoginException
     */
    public function discovery(): array
    {
        $issuer = rtrim((string) $this->setting->oidc_issuer, '/');

        try {
            $document = Cache::remember('oidc_login_disco_'.md5($issuer), 3600, function () use ($issuer) {
                return Http::timeout(10)->acceptJson()->get($issuer.'/.well-known/openid-configuration')->throw()->json();
            });
        } catch (Throwable $e) {
            throw new OidcLoginException('Discovery failed for '.$issuer.': '.$e->getMessage(), previous: $e);
        }

        if (! is_array($document) || rtrim((string) ($document['issuer'] ?? ''), '/') !== $issuer) {
            Cache::forget('oidc_login_disco_'.md5($issuer));

            throw new OidcLoginException('Discovery document issuer does not match the configured issuer');
        }

        return $document;
    }

    /**
     * Build the provider's authorization URL for a fresh login attempt.
     */
    public function authorizationUrl(string $state, string $nonce, string $codeVerifier): string
    {
        $endpoint = $this->discovery()['authorization_endpoint'] ?? null;

        if (empty($endpoint)) {
            throw new OidcLoginException('Discovery document has no authorization_endpoint');
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->setting->oidc_client_id,
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->scopes(),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Exchange the authorization code for tokens and return the verified ID
     * token claims, merged with userinfo claims when the configured username
     * claim is missing from the ID token.
     *
     * The client authenticates with client_secret_basic (the spec default)
     * unless the provider only advertises client_secret_post.
     *
     * @return array<string, mixed>
     *
     * @throws OidcLoginException
     */
    public function claimsFromCode(string $code, string $codeVerifier, string $nonce): array
    {
        $discovery = $this->discovery();

        if (empty($discovery['token_endpoint'])) {
            throw new OidcLoginException('Discovery document has no token_endpoint');
        }

        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $codeVerifier,
        ];

        $request = Http::asForm()->acceptJson()->timeout(10);

        $authMethods = (array) ($discovery['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']);
        if (in_array('client_secret_basic', $authMethods, true)) {
            $request->withBasicAuth(rawurlencode($this->setting->oidc_client_id), rawurlencode($this->clientSecret()));
        } else {
            $payload['client_id'] = $this->setting->oidc_client_id;
            $payload['client_secret'] = $this->clientSecret();
        }

        $response = $request->post($discovery['token_endpoint'], $payload);

        if (! $response->successful() || empty($response->json('id_token'))) {
            throw new OidcLoginException('Token endpoint returned HTTP '.$response->status().' '.($response->json('error') ?? ''));
        }

        $claims = $this->verifyIdToken($response->json('id_token'), $nonce, $discovery);

        if (empty($claims[$this->usernameClaim()]) && ! empty($discovery['userinfo_endpoint']) && $response->json('access_token')) {
            $claims = array_merge($this->userInfo($discovery['userinfo_endpoint'], $response->json('access_token'), (string) $claims['sub']), $claims);
        }

        return $claims;
    }

    /**
     * Verify the ID token signature against the provider's JWKS and check its
     * iss, aud, azp, nonce, sub and exp claims.
     *
     * @param  array<string, mixed>  $discovery
     * @return array<string, mixed>
     *
     * @throws OidcLoginException
     */
    protected function verifyIdToken(string $idToken, string $nonce, array $discovery): array
    {
        try {
            JWT::$leeway = 60;
            $claims = json_decode(json_encode(JWT::decode($idToken, $this->signingKeys($discovery))), true);
        } catch (Throwable $e) {
            throw new OidcLoginException('ID token signature validation failed: '.$e->getMessage(), previous: $e);
        }

        if (($claims['iss'] ?? null) !== $discovery['issuer']) {
            throw new OidcLoginException('ID token issuer mismatch');
        }

        $audiences = (array) ($claims['aud'] ?? []);
        if (! in_array($this->setting->oidc_client_id, $audiences, true)) {
            throw new OidcLoginException('ID token audience mismatch');
        }

        if ((count($audiences) > 1 || isset($claims['azp'])) && ($claims['azp'] ?? null) !== $this->setting->oidc_client_id) {
            throw new OidcLoginException('ID token authorized party mismatch');
        }

        if (! isset($claims['nonce']) || ! hash_equals($nonce, (string) $claims['nonce'])) {
            throw new OidcLoginException('ID token nonce mismatch');
        }

        if (empty($claims['sub']) || empty($claims['exp'])) {
            throw new OidcLoginException('ID token is missing required claims');
        }

        return $claims;
    }

    /**
     * Fetch userinfo claims. They are only used when they describe the same
     * subject as the verified ID token (OIDC Core 5.3.2).
     *
     * @return array<string, mixed>
     */
    protected function userInfo(string $endpoint, string $accessToken, string $subject): array
    {
        $response = Http::timeout(10)->withToken($accessToken)->acceptJson()->get($endpoint);

        $userInfo = $response->successful() ? $response->json() : null;

        if (! is_array($userInfo) || ($userInfo['sub'] ?? null) !== $subject) {
            Log::warning('[OIDC] Ignoring userinfo response for a different or missing subject');

            return [];
        }

        return $userInfo;
    }

    /**
     * The provider's signing keys, cached for an hour.
     *
     * @param  array<string, mixed>  $discovery
     * @return array<string, Key>
     *
     * @throws OidcLoginException
     */
    protected function signingKeys(array $discovery): array
    {
        if (empty($discovery['jwks_uri'])) {
            throw new OidcLoginException('Discovery document has no jwks_uri');
        }

        $jwks = Cache::remember('oidc_login_jwks_'.md5($discovery['jwks_uri']), 3600, function () use ($discovery) {
            return Http::timeout(10)->acceptJson()->get($discovery['jwks_uri'])->throw()->json();
        });

        return JWK::parseKeySet($jwks, 'RS256');
    }

    /**
     * The configured scopes, falling back to the defaults and always
     * including openid.
     */
    protected function scopes(): string
    {
        $scopes = preg_split('/[\s,]+/', (string) $this->setting->oidc_scopes, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($scopes)) {
            return self::DEFAULT_SCOPES;
        }

        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return implode(' ', $scopes);
    }

    /**
     * @throws OidcLoginException
     */
    protected function clientSecret(): string
    {
        try {
            return Crypt::decrypt($this->setting->oidc_client_secret);
        } catch (DecryptException) {
            throw new OidcLoginException('The stored OIDC client secret could not be decrypted. Re-enter it in the OIDC settings.');
        }
    }

    /**
     * PKCE S256 code challenge for the given verifier (RFC 7636).
     */
    public static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
