<?php

namespace App\Http\Controllers;

use App\Exceptions\OidcLoginException;
use App\Helpers\Helper;
use App\Models\User;
use App\Services\Oidc\OidcLoginClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Web UI login through a generic OpenID Connect provider (Entra ID, Okta,
 * Keycloak, Authentik, Auth0, ...). Like the Google login, users are matched
 * to existing Snipe-IT accounts by username and are never provisioned.
 */
class OidcAuthController extends Controller
{
    private const SESSION_KEY = 'oidc_login';

    /**
     * Start a login: remember state, nonce and PKCE verifier in the session
     * and send the user to the provider.
     */
    public function redirectToProvider(Request $request): RedirectResponse
    {
        $client = OidcLoginClient::fromSettings();

        if (! $client->isEnabled()) {
            return $this->failed();
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $codeVerifier = Str::random(64);

        try {
            $url = $client->authorizationUrl($state, $nonce, $codeVerifier);
        } catch (OidcLoginException $e) {
            Log::warning('[OIDC] Could not start login: '.$e->getMessage());

            return $this->failed();
        }

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
        ]);

        return redirect()->away($url);
    }

    /**
     * Complete the login. The pending login is pulled from the session, so a
     * replayed or bookmarked callback finds nothing to complete.
     */
    public function handleProviderCallback(Request $request): RedirectResponse
    {
        $client = OidcLoginClient::fromSettings();

        $pending = $request->session()->pull(self::SESSION_KEY);

        if (! $client->isEnabled() || ! is_array($pending)) {
            return $this->failed();
        }

        if ($request->filled('error') || ! $request->filled('code') || ! is_string($request->input('state')) || ! hash_equals($pending['state'], $request->input('state'))) {
            Log::debug('[OIDC] Callback rejected', ['error' => $request->input('error')]);

            return $this->failed();
        }

        try {
            $claims = $client->claimsFromCode((string) $request->input('code'), $pending['code_verifier'], $pending['nonce']);
        } catch (OidcLoginException $e) {
            Log::warning('[OIDC] Login failed: '.$e->getMessage());

            return $this->failed();
        }

        $username = $claims[$client->usernameClaim()] ?? null;

        if (! is_string($username) || $username === '') {
            Log::warning('[OIDC] ID token has no usable "'.$client->usernameClaim().'" claim');

            return $this->failed();
        }

        $user = User::where('username', $username)
            ->whereNull('deleted_at')
            ->first();

        $user = User::verifyExactUsernameMatch($user, $username);

        if (! $user) {
            Log::debug('[OIDC] User '.$username.' NOT found in Snipe-IT');

            return $this->failed();
        }

        if (! $user->activated) {
            Log::debug('[OIDC] User '.$username.' is deactivated in Snipe-IT');

            return redirect()->route('login')
                ->withErrors(['username' => [trans('auth/message.account_not_activated')]]);
        }

        $user->last_login = \Carbon::now();
        $user->saveQuietly();

        Auth::login($user);

        return Helper::safeIntended(route('home'));
    }

    private function failed(): RedirectResponse
    {
        return redirect()->route('login')
            ->withErrors(['username' => [trans('auth/general.oidc_login_failed')]]);
    }
}
