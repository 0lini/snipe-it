<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use App\Services\Oidc;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OidcLoginTest extends TestCase
{
    private const ISSUER = 'https://idp.example.com/realms/snipe';

    private const CLIENT_ID = 'snipe-it';

    private static ?string $privateKey = null;

    private static array $jwk = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            $details = openssl_pkey_get_details($key);
            self::$privateKey = $pem;
            self::$jwk = [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => 'test-key',
                'n' => $this->base64Url($details['rsa']['n']),
                'e' => $this->base64Url($details['rsa']['e']),
            ];
        }

        $this->settings->set([
            'oidc_enabled' => 1,
            'oidc_issuer' => self::ISSUER,
            'oidc_client_id' => self::CLIENT_ID,
            'oidc_client_secret' => Crypt::encrypt('top-secret'),
            'oidc_username_claim' => 'preferred_username',
            'oidc_auto_create_users' => 0,
            'oidc_forcelogin' => 0,
            'oidc_slo' => 0,
        ]);

        // Without any user the app redirects every request to the setup wizard.
        User::factory()->create(['username' => 'setup-complete']);
    }

    public function test_login_redirects_to_provider_with_state_nonce_and_pkce()
    {
        $this->fakeProvider();

        $response = $this->get(route('oidc.login'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/protocol/openid-connect/auth?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $pending = session(Oidc::AUTH_SESSION_KEY);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(self::CLIENT_ID, $query['client_id']);
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

    public function test_login_is_forbidden_when_oidc_is_disabled()
    {
        $this->settings->set(['oidc_enabled' => 0]);

        $this->get(route('oidc.login'))->assertForbidden();
    }

    public function test_existing_user_is_logged_in_with_valid_id_token()
    {
        $user = User::factory()->create(['username' => 'ada']);
        $this->fakeProvider($this->idToken(['preferred_username' => 'ada']));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']))
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login);

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === self::ISSUER.'/protocol/openid-connect/token'
                && $request['code'] === 'auth-code'
                && $request['code_verifier'] === 'expected-verifier'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::CLIENT_ID.':top-secret'));
        });
    }

    public function test_state_mismatch_is_rejected()
    {
        User::factory()->create(['username' => 'ada']);
        $this->fakeProvider($this->idToken(['preferred_username' => 'ada']));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'forged-state']))
            ->assertRedirect(route('login', ['nooidc' => 1]));

        $this->assertGuest();
        Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/token'));
    }

    public function test_callback_without_pending_authorization_is_rejected()
    {
        $this->fakeProvider($this->idToken(['preferred_username' => 'ada']));
        User::factory()->create(['username' => 'ada']);

        $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']))
            ->assertRedirect(route('login', ['nooidc' => 1]));

        $this->assertGuest();
    }

    public function test_id_token_with_wrong_nonce_is_rejected()
    {
        User::factory()->create(['username' => 'ada']);
        $this->fakeProvider($this->idToken(['preferred_username' => 'ada', 'nonce' => 'replayed-nonce']));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']));

        $this->assertGuest();
    }

    public function test_id_token_for_another_audience_is_rejected()
    {
        User::factory()->create(['username' => 'ada']);
        $this->fakeProvider($this->idToken(['preferred_username' => 'ada', 'aud' => 'some-other-client']));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']));

        $this->assertGuest();
    }

    public function test_id_token_from_another_issuer_is_rejected()
    {
        User::factory()->create(['username' => 'ada']);
        $this->fakeProvider($this->idToken(['preferred_username' => 'ada', 'iss' => 'https://evil.example.com']));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']));

        $this->assertGuest();
    }

    public function test_expired_id_token_is_rejected()
    {
        User::factory()->create(['username' => 'ada']);
        $this->fakeProvider($this->idToken(['preferred_username' => 'ada', 'exp' => time() - 3600, 'iat' => time() - 7200]));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']));

        $this->assertGuest();
    }

    public function test_id_token_signed_with_unknown_key_is_rejected()
    {
        User::factory()->create(['username' => 'ada']);
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($otherKey, $otherPem);
        $this->fakeProvider($this->idToken(['preferred_username' => 'ada'], $otherPem));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']));

        $this->assertGuest();
    }

    public function test_provider_error_response_is_rejected()
    {
        $this->fakeProvider();

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['error' => 'access_denied', 'state' => 'expected-state']))
            ->assertRedirect(route('login', ['nooidc' => 1]));

        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_log_in()
    {
        User::factory()->create(['username' => 'ada', 'activated' => 0]);
        $this->settings->set(['oidc_auto_create_users' => 1]);
        $this->fakeProvider($this->idToken(['preferred_username' => 'ada']));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']));

        $this->assertGuest();
        $this->assertSame(1, User::withTrashed()->where('username', 'ada')->count());
    }

    public function test_unknown_user_is_rejected_when_auto_create_is_disabled()
    {
        $this->fakeProvider($this->idToken(['preferred_username' => 'ada']));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['username' => 'ada']);
    }

    public function test_unknown_user_is_created_from_claims_when_auto_create_is_enabled()
    {
        $this->settings->set(['oidc_auto_create_users' => 1]);
        $this->fakeProvider($this->idToken([
            'preferred_username' => 'ada',
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']));

        $user = User::where('username', 'ada')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Ada', $user->first_name);
        $this->assertSame('Lovelace', $user->last_name);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertFalse($user->isSuperUser());
        $this->assertFalse($user->isAdmin());
    }

    public function test_configured_username_claim_is_used()
    {
        $this->settings->set(['oidc_username_claim' => 'email']);
        $user = User::factory()->create(['username' => 'ada@example.com']);
        $this->fakeProvider($this->idToken(['preferred_username' => 'someone-else', 'email' => 'ada@example.com']));

        $this->withSession($this->pendingAuth())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'expected-state']));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_page_redirects_to_provider_when_forced()
    {
        $this->settings->set(['oidc_forcelogin' => 1]);

        $this->get(route('login'))->assertRedirect(route('oidc.login'));
        $this->get(route('login', ['nooidc' => 1]))->assertOk();
    }

    public function test_login_page_shows_oidc_button()
    {
        $this->settings->set(['oidc_display_name' => 'Acme SSO']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('oidc.login'))
            ->assertSee('Acme SSO');
    }

    public function test_logout_redirects_to_provider_end_session_when_enabled()
    {
        $this->settings->set(['oidc_slo' => 1]);
        $this->fakeProvider();

        $response = $this->actingAs(User::factory()->create())
            ->withSession([Oidc::ID_TOKEN_SESSION_KEY => 'the-id-token'])
            ->post(route('logout.post'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/protocol/openid-connect/logout?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('the-id-token', $query['id_token_hint']);
        $this->assertSame(route('login'), $query['post_logout_redirect_uri']);
        $this->assertGuest();
    }

    public function test_logout_stays_local_when_single_logout_is_disabled()
    {
        $this->fakeProvider();

        $this->actingAs(User::factory()->create())
            ->withSession([Oidc::ID_TOKEN_SESSION_KEY => 'the-id-token'])
            ->post(route('logout.post'))
            ->assertRedirect(route('login'));
    }

    private function pendingAuth(): array
    {
        return [Oidc::AUTH_SESSION_KEY => [
            'state' => 'expected-state',
            'nonce' => 'expected-nonce',
            'code_verifier' => 'expected-verifier',
        ]];
    }

    private function idToken(array $claims, ?string $privateKey = null): string
    {
        return JWT::encode(array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => '1234',
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => 'expected-nonce',
        ], $claims), $privateKey ?? self::$privateKey, 'RS256', 'test-key');
    }

    private function fakeProvider(?string $idToken = null): void
    {
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/protocol/openid-connect/auth',
                'token_endpoint' => self::ISSUER.'/protocol/openid-connect/token',
                'jwks_uri' => self::ISSUER.'/protocol/openid-connect/certs',
                'end_session_endpoint' => self::ISSUER.'/protocol/openid-connect/logout',
            ]),
            self::ISSUER.'/protocol/openid-connect/certs' => Http::response(['keys' => [self::$jwk]]),
            self::ISSUER.'/protocol/openid-connect/token' => Http::response([
                'access_token' => 'access',
                'token_type' => 'Bearer',
                'id_token' => $idToken,
            ]),
        ]);
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
