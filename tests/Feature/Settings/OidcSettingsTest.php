<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class OidcSettingsTest extends TestCase
{
    public function test_requires_permission()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.oidc.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('settings.oidc.save'), ['oidc_enabled' => '1'])
            ->assertForbidden();
    }

    public function test_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.oidc.index'))
            ->assertOk()
            ->assertSee(route('oidc.callback'));
    }

    public function test_settings_can_be_saved_and_secret_is_encrypted()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), [
                'oidc_enabled' => '1',
                'oidc_display_name' => 'Acme SSO',
                'oidc_issuer' => 'https://idp.example.com/realms/snipe/',
                'oidc_client_id' => 'snipe-it',
                'oidc_client_secret' => 'top-secret',
                'oidc_username_claim' => 'email',
                'oidc_auto_create_users' => '1',
            ])
            ->assertRedirect(route('settings.oidc.index'))
            ->assertSessionHasNoErrors();

        $setting = Setting::getSettings()->fresh();
        $this->assertEquals(1, $setting->oidc_enabled);
        $this->assertSame('https://idp.example.com/realms/snipe', $setting->oidc_issuer);
        $this->assertSame('snipe-it', $setting->oidc_client_id);
        $this->assertSame('email', $setting->oidc_username_claim);
        $this->assertEquals(1, $setting->oidc_auto_create_users);
        $this->assertNotSame('top-secret', $setting->oidc_client_secret);
        $this->assertSame('top-secret', Crypt::decrypt($setting->oidc_client_secret));
    }

    public function test_blank_secret_keeps_existing_secret()
    {
        $this->settings->set(['oidc_client_secret' => Crypt::encrypt('existing')]);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), [
                'oidc_enabled' => '1',
                'oidc_issuer' => 'https://idp.example.com',
                'oidc_client_id' => 'snipe-it',
                'oidc_client_secret' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('existing', Crypt::decrypt(Setting::getSettings()->fresh()->oidc_client_secret));
    }

    public function test_secret_can_be_cleared()
    {
        $this->settings->set(['oidc_client_secret' => Crypt::encrypt('existing')]);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), [
                'oidc_client_secret_clear' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::getSettings()->fresh()->oidc_client_secret);
    }

    public function test_issuer_and_client_id_are_required_when_enabled()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), ['oidc_enabled' => '1'])
            ->assertSessionHasErrors(['oidc_issuer', 'oidc_client_id']);
    }
}
