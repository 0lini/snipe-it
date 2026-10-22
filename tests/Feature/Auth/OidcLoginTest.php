<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Web UI login through a generic OpenID Connect provider. The provider's
 * discovery, JWKS, token and userinfo endpoints are faked and ID tokens are
 * signed with a local RSA key, so signature and claim validation run for real.
 */
class OidcLoginTest extends TestCase
{
    private const ISSUER = 'https://idp.example.com';

    private string $privateKey;

    /** @var array<string, mixed> */
    private array $jwks;

    /** @var array<string, mixed> */
    private array $tokenResponse = [];

    /** @var array<string, mixed> */
    private array $userInfo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings->enableOidcLogin();

        [$this->privateKey, $this->jwks] = $this->generateKeyPair('test-key');

        Http::fake(function (HttpRequest $request) {
            return match (strtok($request->url(), '?')) {
                self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                    'issuer' => self::ISSUER,
                    'authorization_endpoint' => self::ISSUER.'/authorize',
                    'token_endpoint' => self::ISSUER.'/token',
                    'userinfo_endpoint' => self::ISSUER.'/userinfo',
                    'jwks_uri' => self::ISSUER.'/jwks',
                ]),
                self::ISSUER.'/jwks' => Http::response($this->jwks),
                self::ISSUER.'/token' => Http::response($this->tokenResponse),
                self::ISSUER.'/userinfo' => Http::response($this->userInfo),
                default => Http::response(null, 404),
            };
        });
    }

    public function test_redirect_sends_user_to_provider_with_state_nonce_and_pkce(): void
    {
        User::factory()->create();

        $response = $this->get(route('oidc.redirect'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/authorize?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $pending = session('oidc_login');

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('snipe-it', $query['client_id']);
        $this->assertSame(route('oidc.callback'), $query['redirect_uri']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame($pending['state'], $query['state']);
        $this->assertSame($pending['nonce'], $query['nonce']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $pending['code_verifier'], true)), '+/', '-_'), '='),
            $query['code_challenge']
        );
    }

    public function test_redirect_fails_when_oidc_login_is_disabled(): void
    {
        User::factory()->create();
        $this->settings->set(['oidc_login' => 0]);

        $this->get(route('oidc.redirect'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        Http::assertNothingSent();
    }

    public function test_successful_callback_logs_in_matching_user(): void
    {
        $user = User::factory()->create(['username' => 'jdoe', 'last_login' => null]);

        $this->completeLogin(['preferred_username' => 'jdoe'])
            ->assertRedirect(route('home'));

        $this->assertTrue(Auth::check());
        $this->assertTrue(Auth::user()->is($user));
        $this->assertNotNull($user->fresh()->last_login);

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === self::ISSUER.'/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'auth-code'
                && ! empty($request['code_verifier'])
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('snipe-it:oidc-secret'));
        });
    }

    public function test_callback_with_mismatched_state_is_rejected_before_token_exchange(): void
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->get(route('oidc.redirect'));

        $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'forged']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertFalse(Auth::check());
        Http::assertNotSent(fn (HttpRequest $request) => $request->url() === self::ISSUER.'/token');
    }

    public function test_callback_without_pending_login_is_rejected(): void
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'anything']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    public function test_id_token_with_wrong_nonce_is_rejected(): void
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->completeLogin(['preferred_username' => 'jdoe', 'nonce' => 'replayed-nonce'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    public function test_id_token_for_another_client_is_rejected(): void
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->completeLogin(['preferred_username' => 'jdoe', 'aud' => 'some-other-app'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    public function test_id_token_from_another_issuer_is_rejected(): void
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->completeLogin(['preferred_username' => 'jdoe', 'iss' => 'https://evil.example.com'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    public function test_id_token_signed_with_unknown_key_is_rejected(): void
    {
        User::factory()->create(['username' => 'jdoe']);

        [$otherKey] = $this->generateKeyPair('test-key');

        $this->completeLogin(['preferred_username' => 'jdoe'], $otherKey)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    public function test_unknown_user_is_not_provisioned(): void
    {
        User::factory()->create();

        $this->completeLogin(['preferred_username' => 'nobody'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertFalse(Auth::check());
        $this->assertDatabaseMissing('users', ['username' => 'nobody']);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        User::factory()->create(['username' => 'jdoe', 'activated' => 0]);

        $this->completeLogin(['preferred_username' => 'jdoe'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => trans('auth/message.account_not_activated')]);

        $this->assertFalse(Auth::check());
    }

    public function test_username_claim_missing_from_id_token_is_read_from_userinfo(): void
    {
        $this->settings->set(['oidc_username_claim' => 'email']);
        $user = User::factory()->create(['username' => 'jdoe@example.com']);

        $this->userInfo = ['sub' => 'subject-1', 'email' => 'jdoe@example.com'];

        $this->completeLogin([])
            ->assertRedirect(route('home'));

        $this->assertTrue(Auth::user()->is($user));
    }

    public function test_userinfo_for_a_different_subject_is_ignored(): void
    {
        $this->settings->set(['oidc_username_claim' => 'email']);
        User::factory()->create(['username' => 'jdoe@example.com']);

        $this->userInfo = ['sub' => 'someone-else', 'email' => 'jdoe@example.com'];

        $this->completeLogin([])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertFalse(Auth::check());
    }

    /**
     * Start a login, have the fake provider issue an ID token with the given
     * claim overrides, and return the callback response.
     *
     * @param  array<string, mixed>  $claims
     */
    private function completeLogin(array $claims, ?string $signingKey = null): \Illuminate\Testing\TestResponse
    {
        $this->get(route('oidc.redirect'));
        $pending = session('oidc_login');

        $idToken = JWT::encode(array_merge([
            'iss' => self::ISSUER,
            'aud' => 'snipe-it',
            'sub' => 'subject-1',
            'nonce' => $pending['nonce'],
            'iat' => time(),
            'exp' => time() + 300,
        ], $claims), $signingKey ?? $this->privateKey, 'RS256', 'test-key');

        $this->tokenResponse = [
            'access_token' => 'access-token',
            'token_type' => 'Bearer',
            'id_token' => $idToken,
        ];

        return $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => $pending['state']]));
    }

    /**
     * @return array{0: string, 1: array{keys: list<array<string, string>>}}
     */
    private function generateKeyPair(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $privatePem = '';
        openssl_pkey_export($key, $privatePem);
        $rsa = openssl_pkey_get_details($key)['rsa'];

        return [$privatePem, ['keys' => [[
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => JWT::urlsafeB64Encode($rsa['n']),
            'e' => JWT::urlsafeB64Encode($rsa['e']),
        ]]]];
    }
}
