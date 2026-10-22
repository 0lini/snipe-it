<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Gate;

class StoreOidcSettings extends Request
{
    /**
     * @var array<string, string>
     */
    protected $rules = [
        'oidc_login' => 'nullable|boolean',
        'oidc_issuer' => 'nullable|required_if:oidc_login,1|url:https|max:191',
        'oidc_client_id' => 'nullable|required_if:oidc_login,1|string|max:191',
        'oidc_client_secret' => 'nullable|string|max:1000',
        'clear_oidc_client_secret' => 'nullable|boolean',
        'oidc_scopes' => 'nullable|string|max:191',
        'oidc_username_claim' => 'nullable|string|max:191',
        'oidc_button_label' => 'nullable|string|max:191',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('superuser');
    }
}
