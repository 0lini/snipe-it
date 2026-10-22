@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/settings/general.oidc_login') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

    <x-container class="col-sm-10 col-sm-offset-1 col-md-8 col-md-offset-2">
        <x-form :route="route('settings.oidc.save')">
            <x-box>
                <x-slot:header>
                    <x-icon type="oidc"/> {{ trans('admin/settings/general.oidc_login') }}
                </x-slot:header>

                {{-- Single demo-mode banner. Renders nothing outside demo mode. --}}
                <x-demo-callout />

                <div class="col-md-12">

                    {{-- Read-only display of the computed callback URL. --}}
                    <div class="form-group">
                        <div class="col-md-3 control-label">
                            <strong>{{ trans('admin/settings/general.redirect_url') }}</strong>
                        </div>
                        <div class="col-md-8">
                            <p class="form-control-static"><code>{{ route('oidc.callback') }}</code></p>
                            <p class="help-block">{{ trans('admin/settings/general.oidc_callback_help') }}</p>
                        </div>
                    </div>

                    <x-form.checkbox-row
                        name="oidc_login"
                        :label="trans('admin/settings/general.enable_oidc_login')"
                        :help_text="trans('admin/settings/general.enable_oidc_login_help')"
                        :item="$setting"
                        :disabled="config('app.lock_passwords') === true"
                    />

                    <x-form.row
                        :label="trans('admin/settings/general.oidc_issuer')"
                        name="oidc_issuer"
                        input_div_class="col-md-8"
                        help_html="{!! trans('admin/settings/general.oidc_issuer_help') !!}"
                    >
                        <x-slot:input>
                            <x-input.text
                                name="oidc_issuer"
                                :value="old('oidc_issuer', $setting->oidc_issuer)"
                                :placeholder="trans('general.example') . 'https://login.example.com/realms/acme'"
                                :disabled="config('app.lock_passwords') === true"
                            />
                        </x-slot:input>
                    </x-form.row>

                    <x-form.row
                        :label="trans('admin/settings/general.client_id')"
                        name="oidc_client_id"
                        input_div_class="col-md-8"
                    >
                        <x-slot:input>
                            <x-input.text
                                name="oidc_client_id"
                                :value="old('oidc_client_id', $setting->oidc_client_id)"
                                :disabled="config('app.lock_passwords') === true"
                            />
                        </x-slot:input>
                    </x-form.row>

                    {{-- Write-only: the stored (encrypted) secret is never sent back to the browser. --}}
                    <x-form.row
                        :label="trans('admin/settings/general.client_secret')"
                        name="oidc_client_secret"
                        input_div_class="col-md-8"
                        :help_text="trans('admin/settings/general.oidc_client_secret_help')"
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
                            name="clear_oidc_client_secret"
                            :label="trans('admin/settings/general.oidc_clear_client_secret')"
                            :checked="false"
                            :disabled="config('app.lock_passwords') === true"
                        />
                    @endif

                    <x-form.row
                        :label="trans('admin/settings/general.oidc_scopes')"
                        name="oidc_scopes"
                        input_div_class="col-md-8"
                        help_html="{!! trans('admin/settings/general.oidc_scopes_help') !!}"
                    >
                        <x-slot:input>
                            <x-input.text
                                name="oidc_scopes"
                                :value="old('oidc_scopes', $setting->oidc_scopes)"
                                :placeholder="\App\Services\Oidc\OidcLoginClient::DEFAULT_SCOPES"
                                :disabled="config('app.lock_passwords') === true"
                            />
                        </x-slot:input>
                    </x-form.row>

                    <x-form.row
                        :label="trans('admin/settings/general.oidc_username_claim')"
                        name="oidc_username_claim"
                        input_div_class="col-md-8"
                        help_html="{!! trans('admin/settings/general.oidc_username_claim_help') !!}"
                    >
                        <x-slot:input>
                            <x-input.text
                                name="oidc_username_claim"
                                :value="old('oidc_username_claim', $setting->oidc_username_claim)"
                                :placeholder="\App\Services\Oidc\OidcLoginClient::DEFAULT_USERNAME_CLAIM"
                                :disabled="config('app.lock_passwords') === true"
                            />
                        </x-slot:input>
                    </x-form.row>

                    <x-form.row
                        :label="trans('admin/settings/general.oidc_button_label')"
                        name="oidc_button_label"
                        input_div_class="col-md-8"
                        :help_text="trans('admin/settings/general.oidc_button_label_help', ['default' => trans('auth/general.oidc_login')])"
                    >
                        <x-slot:input>
                            <x-input.text
                                name="oidc_button_label"
                                :value="old('oidc_button_label', $setting->oidc_button_label)"
                                :placeholder="trans('auth/general.oidc_login')"
                                :disabled="config('app.lock_passwords') === true"
                            />
                        </x-slot:input>
                    </x-form.row>

                </div>

                <x-slot:customfooter>
                    <div class="box-footer">
                        <div class="text-left col-md-6">
                            <a class="btn btn-link text-left" href="{{ route('settings.index') }}">{{ trans('button.cancel') }}</a>
                        </div>
                        <div class="text-right col-md-6">
                            <x-button.submit class="btn-success" :disabled="config('app.lock_passwords') === true" />
                        </div>
                    </div>
                </x-slot:customfooter>
            </x-box>
        </x-form>
    </x-container>

@stop
