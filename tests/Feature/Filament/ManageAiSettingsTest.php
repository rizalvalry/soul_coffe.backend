<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Pages\ManageAiSettings;
use App\Models\AiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Gemini key lives here now instead of .env — see ManageAiSettings' docblock for why. Every
 * test below exists because this page is the one place in the panel that holds a real API
 * credential, so its access boundary and its persistence both need direct proof, not just an
 * inference from NewsPostResource's own tests.
 */
class ManageAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Role $role): User
    {
        return User::create([
            'name' => 'Panel '.$role->value,
            'phone_e164' => '0811900'.rand(100, 999),
            'password' => 'rahasia123',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_an_administrator_can_open_the_page(): void
    {
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->get(ManageAiSettings::getUrl())
            ->assertSuccessful();
    }

    /** Panel-only CONTENT_CREATOR still isn't an administrator — this is billing credentials, not editorial content. */
    public function test_a_content_creator_is_refused(): void
    {
        $this->actingAs($this->makeUser(Role::CONTENT_CREATOR))
            ->get(ManageAiSettings::getUrl())
            ->assertForbidden();
    }

    public function test_an_operational_role_is_refused(): void
    {
        $this->actingAs($this->makeUser(Role::STAFF))
            ->get(ManageAiSettings::getUrl())
            ->assertForbidden();
    }

    public function test_saving_persists_the_key_and_model(): void
    {
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        Livewire::test(ManageAiSettings::class)
            ->fillForm([
                'gemini_api_key' => 'test-gemini-key',
                'gemini_model' => 'gemini-1.5-pro',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = AiSetting::current();
        $this->assertSame('test-gemini-key', $setting->gemini_api_key);
        $this->assertSame('gemini-1.5-pro', $setting->gemini_model);
    }

    /**
     * Also pins `current()` always resolving to the same row (id 1) regardless of how many times
     * or in what order it's been called before — the bug this guards against had it silently
     * creating a fresh, differently-numbered row every time id 1 didn't exist yet, which meant
     * two calls in the same request could each be talking to a different "the" settings row.
     */
    public function test_the_key_is_encrypted_in_the_database_and_current_always_resolves_to_row_one(): void
    {
        $setting = AiSetting::current();
        $setting->update(['gemini_api_key' => 'plain-text-key']);

        $this->assertSame(1, $setting->id);

        $raw = DB::table('ai_settings')->where('id', 1)->value('gemini_api_key');
        $this->assertNotSame('plain-text-key', $raw);

        $this->assertSame('plain-text-key', AiSetting::current()->gemini_api_key);
    }
}
