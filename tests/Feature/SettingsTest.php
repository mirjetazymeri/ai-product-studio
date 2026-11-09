<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_the_settings_page_renders_without_asking_for_api_keys(): void
    {
        $this->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('AI provider')
            ->assertDontSee('api_key');
    }

    public function test_the_profile_can_be_updated(): void
    {
        $this->patch(route('settings.profile.update'), ['name' => 'New Name', 'email' => 'new@example.com'])
            ->assertRedirect(route('settings.edit'));

        $this->user->refresh();

        $this->assertSame('New Name', $this->user->name);
        $this->assertSame('new@example.com', $this->user->email);
        $this->assertNull($this->user->email_verified_at);
    }

    public function test_keeping_the_same_email_preserves_verification(): void
    {
        $this->patch(route('settings.profile.update'), ['name' => 'New Name', 'email' => $this->user->email]);

        $this->assertNotNull($this->user->fresh()->email_verified_at);
    }

    public function test_the_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->patch(route('settings.profile.update'), ['name' => 'Name', 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_the_password_can_be_changed(): void
    {
        $this->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasNoErrors()->assertRedirect(route('settings.edit'));

        $this->assertTrue(Hash::check('new-password-123', $this->user->fresh()->password));
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $this->put(route('settings.password.update'), [
            'current_password' => 'wrong',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }
}
