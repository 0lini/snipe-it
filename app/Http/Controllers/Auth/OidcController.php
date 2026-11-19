<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Services\Oidc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenID Connect login endpoints.
 */
class OidcController extends Controller
{
    public function __construct(protected Oidc $oidc)
    {
        parent::__construct();
        $this->middleware('guest');
    }

    /**
     * Send the browser to the provider's authorization endpoint.
     *
     * /login/oidc
     */
    public function redirect(): RedirectResponse
    {
        if (! $this->oidc->isEnabled()) {
            abort(403, 'OIDC not enabled.');
        }

        try {
            return redirect()->away($this->oidc->getAuthorizationUrl());
        } catch (Throwable $e) {
            Log::warning('OIDC login could not start: '.$e->getMessage());

            return $this->failed();
        }
    }

    /**
     * Receive the authorization response, verify it and log the user in.
     *
     * /oidc/callback
     */
    public function callback(Request $request): RedirectResponse
    {
        if (! $this->oidc->isEnabled()) {
            abort(403, 'OIDC not enabled.');
        }

        if ($request->has('error')) {
            Log::debug('OIDC provider returned an error: '.$request->query('error').' '.$request->query('error_description'));
            $request->session()->forget(Oidc::AUTH_SESSION_KEY);

            return $this->failed();
        }

        try {
            $claims = $this->oidc->handleCallback($request->query('code'), $request->query('state'));
        } catch (Throwable $e) {
            Log::warning('OIDC login failed: '.$e->getMessage());

            return $this->failed();
        }

        $user = $this->oidc->findOrCreateUser($claims);

        if (is_null($user)) {
            $request->session()->forget(Oidc::ID_TOKEN_SESSION_KEY);

            return $this->failed();
        }

        Auth::login($user);
        $request->session()->regenerate();

        $user->last_login = \Carbon::now();
        $user->saveQuietly();

        return Helper::safeIntended('/');
    }

    private function failed(): RedirectResponse
    {
        return redirect()->route('login', ['nooidc' => 1])->with('error', trans('auth/general.oidc_login_failed'));
    }
}
