<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class OidcSettingsTest extends TestCase
{
    public function test_page_renders(): void
    {
        $this->settings->enableOidcLogin();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.oidc.index'))
            ->assertOk()
            ->assertSee(route('oidc.callback'))
            ->assertDontSee('oidc-secret');
    }

    public function test_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.oidc.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('settings.oidc.save'), ['oidc_login' => 1])
            ->assertForbidden();
    }

    public function test_settings_are_saved_with_encrypted_secret(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), [
                'oidc_login' => 1,
                'oidc_issuer' => 'https://login.example.com/realms/acme/',
                'oidc_client_id' => 'snipe-it',
                'oidc_client_secret' => 'top-secret',
                'oidc_username_claim' => 'email',
            ])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasNoErrors();

        $setting = Setting::getSettings()->fresh();

        $this->assertEquals(1, $setting->oidc_login);
        $this->assertSame('https://login.example.com/realms/acme', $setting->oidc_issuer);
        $this->assertSame('snipe-it', $setting->oidc_client_id);
        $this->assertSame('email', $setting->oidc_username_claim);
        $this->assertNotSame('top-secret', $setting->oidc_client_secret);
        $this->assertSame('top-secret', Crypt::decrypt($setting->oidc_client_secret));
    }

    public function test_blank_secret_keeps_the_stored_secret(): void
    {
        $this->settings->enableOidcLogin();

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), [
                'oidc_login' => 1,
                'oidc_issuer' => 'https://idp.example.com',
                'oidc_client_id' => 'snipe-it',
                'oidc_client_secret' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('oidc-secret', Crypt::decrypt(Setting::getSettings()->fresh()->oidc_client_secret));
    }

    public function test_issuer_and_client_id_are_required_when_enabled(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), ['oidc_login' => 1])
            ->assertSessionHasErrors(['oidc_issuer', 'oidc_client_id']);
    }

    public function test_login_page_shows_button_only_when_configured(): void
    {
        User::factory()->create();

        $this->get(route('login'))
            ->assertDontSee(route('oidc.redirect'));

        $this->settings->enableOidcLogin()->set(['oidc_button_label' => 'Sign in with Acme SSO']);

        $this->get(route('login'))
            ->assertSee(route('oidc.redirect'))
            ->assertSee('Sign in with Acme SSO');
    }
}
