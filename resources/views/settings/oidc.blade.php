@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/settings/general.oidc_title') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

    <x-container class="col-sm-10 col-sm-offset-1 col-md-8 col-md-offset-2">
        <x-form :route="route('settings.oidc.save')">

            <x-box>
                <x-slot:header>
                    <x-icon type="oidc"/> {{ trans('admin/settings/general.oidc') }}
                </x-slot:header>

                <x-demo-callout />

                {{-- Enable OIDC --}}
                <x-form.checkbox-row
                    name="oidc_enabled"
                    :label="trans('admin/settings/general.oidc_enabled')"
                    :item="$setting"
                    :disabled="config('app.lock_passwords') === true"
                />

                {{-- Redirect URI (readonly, register at the provider) --}}
                <x-form.row
                    :label="trans('admin/settings/general.oidc_redirect_uri')"
                    name="oidc_redirect_uri"
                    :help_text="trans('admin/settings/general.oidc_redirect_uri_help')"
                    input_div_class="col-md-8"
                >
                    <x-slot:input>
                        <input class="form-control" readonly name="oidc_redirect_uri" type="text" value="{{ route('oidc.callback') }}" id="oidc_redirect_uri">
                    </x-slot:input>
                </x-form.row>

                {{-- Post-logout redirect URI (readonly) --}}
                <x-form.row
                    :label="trans('admin/settings/general.oidc_post_logout_redirect_uri')"
                    name="oidc_post_logout_redirect_uri"
                    :help_text="trans('admin/settings/general.oidc_post_logout_redirect_uri_help')"
                    input_div_class="col-md-8"
                >
                    <x-slot:input>
                        <input class="form-control" readonly name="oidc_post_logout_redirect_uri" type="text" value="{{ route('login') }}" id="oidc_post_logout_redirect_uri">
                    </x-slot:input>
                </x-form.row>

                {{-- Login button label --}}
                <x-form.row
                    :label="trans('admin/settings/general.oidc_display_name')"
                    name="oidc_display_name"
                    type="text"
                    :item="$setting"
                    :help_text="trans('admin/settings/general.oidc_display_name_help')"
                    :disabled="config('app.lock_passwords')"
                    input_div_class="col-md-8"
                />

                {{-- Issuer --}}
                <x-form.row
                    :label="trans('admin/settings/general.oidc_issuer')"
                    name="oidc_issuer"
                    type="text"
                    :item="$setting"
                    :help_html="trans('admin/settings/general.oidc_issuer_help')"
                    placeholder="https://login.example.com/realms/snipe-it"
                    :disabled="config('app.lock_passwords')"
                    input_div_class="col-md-8"
                />

                {{-- Client ID --}}
                <x-form.row
                    :label="trans('admin/settings/general.client_id')"
                    name="oidc_client_id"
                    type="text"
                    :item="$setting"
                    :disabled="config('app.lock_passwords')"
                    input_div_class="col-md-8"
                />

                {{-- Client secret: write-only, never echoed back --}}
                <x-form.row
                    :label="trans('admin/settings/general.client_secret')"
                    name="oidc_client_secret"
                    :help_text="trans('admin/settings/general.oidc_client_secret_help')"
                    input_div_class="col-md-8"
                >
                    <x-slot:input>
                        <x-input.password
                            name="oidc_client_secret"
                            :placeholder="$setting->oidc_client_secret ? trans('admin/settings/general.oidc_client_secret_set') : null"
                            :disabled="config('app.lock_passwords') === true"
                            ignoreAutofill
                        />
                    </x-slot:input>
                </x-form.row>

                @if ($setting->oidc_client_secret)
                    <x-form.checkbox-row
                        name="oidc_client_secret_clear"
                        :label="trans('admin/settings/general.oidc_client_secret_clear')"
                        :disabled="config('app.lock_passwords') === true"
                    />
                @endif

                {{-- Scopes --}}
                <x-form.row
                    :label="trans('admin/settings/general.oidc_scopes')"
                    name="oidc_scopes"
                    type="text"
                    :item="$setting"
                    :help_text="trans('admin/settings/general.oidc_scopes_help')"
                    :placeholder="\App\Services\Oidc::DEFAULT_SCOPES"
                    :disabled="config('app.lock_passwords')"
                    input_div_class="col-md-8"
                />

                {{-- Username claim --}}
                <x-form.row
                    :label="trans('admin/settings/general.oidc_username_claim')"
                    name="oidc_username_claim"
                    type="text"
                    :item="$setting"
                    :help_text="trans('admin/settings/general.oidc_username_claim_help')"
                    :placeholder="\App\Services\Oidc::DEFAULT_USERNAME_CLAIM"
                    :disabled="config('app.lock_passwords')"
                    input_div_class="col-md-8"
                />

                {{-- Force login --}}
                <x-form.checkbox-row
                    name="oidc_forcelogin"
                    :label="trans('admin/settings/general.oidc_forcelogin')"
                    :help_text="trans('admin/settings/general.oidc_forcelogin_help')"
                    :item="$setting"
                    :disabled="config('app.lock_passwords') === true"
                />

                {{-- Single logout --}}
                <x-form.checkbox-row
                    name="oidc_slo"
                    :label="trans('admin/settings/general.oidc_slo')"
                    :help_text="trans('admin/settings/general.oidc_slo_help')"
                    :item="$setting"
                    :disabled="config('app.lock_passwords') === true"
                />

                {{-- Auto-create users --}}
                <x-form.checkbox-row
                    name="oidc_auto_create_users"
                    :label="trans('admin/settings/general.oidc_auto_create_users')"
                    :help_text="trans('admin/settings/general.oidc_auto_create_users_help')"
                    :item="$setting"
                    :disabled="config('app.lock_passwords') === true"
                />

                <x-slot:customfooter>
                    <div class="box-footer">
                        <div class="text-left col-md-6">
                            <a class="btn btn-link text-left" href="{{ route('settings.index') }}">{{ trans('button.cancel') }}</a>
                        </div>
                        <div class="text-right col-md-6">
                            <x-button.submit class="btn-theme" :disabled="config('app.lock_passwords') === true" />
                        </div>
                    </div>
                </x-slot:customfooter>
            </x-box>

        </x-form>
    </x-container>

@stop
