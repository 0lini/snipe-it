<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates OpenID Connect settings submitted by a superuser.
 */
class SettingsOidcRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'oidc_enabled' => ['nullable', 'boolean'],
            'oidc_display_name' => ['nullable', 'string', 'max:191'],
            'oidc_issuer' => ['nullable', 'required_if:oidc_enabled,1', 'url:https,http', 'max:191'],
            'oidc_client_id' => ['nullable', 'required_if:oidc_enabled,1', 'string', 'max:191'],
            'oidc_client_secret' => ['nullable', 'string', 'max:2000'],
            'oidc_scopes' => ['nullable', 'string', 'max:191'],
            'oidc_username_claim' => ['nullable', 'string', 'max:191'],
            'oidc_forcelogin' => ['nullable', 'boolean'],
            'oidc_slo' => ['nullable', 'boolean'],
            'oidc_auto_create_users' => ['nullable', 'boolean'],
        ];
    }
}
