<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Settings\ApiTokenController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\UsesApiTokens;
use Tests\TestCase;

class ApiTokenManagementTest extends TestCase
{
    use RefreshDatabase, UsesApiTokens;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function createToken(array $overrides = [])
    {
        return $this->post(route('settings.api-tokens.store'), [
            'name' => 'ERP connector',
            'abilities' => ['products:read', 'ai:read'],
            ...$overrides,
        ]);
    }

    public function test_settings_shows_the_api_access_section(): void
    {
        $this->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('API Access')
            ->assertSee('Generate Token')
            ->assertSee('Token Permissions')
            ->assertSee('products:write')
            ->assertSee(route('settings.api-docs', 'README.md'));
    }

    public function test_a_scoped_token_is_created_and_shown_exactly_once(): void
    {
        $response = $this->createToken(['expires_at' => now()->addDays(10)->toDateString()]);

        $response->assertRedirect()->assertSessionHas(ApiTokenController::FLASH_KEY);

        $token = $this->user->tokens()->sole();
        $this->assertSame(['products:read', 'ai:read'], $token->abilities);
        $this->assertTrue($token->expires_at->isSameDay(now()->addDays(10)));

        $page = $this->get(route('settings.edit'))->assertOk()->assertSee('won\'t be shown again', false);
        preg_match('/id="new-api-token"[^>]*value="([^"]+)"/', $page->getContent(), $matches);
        $plain = html_entity_decode($matches[1]);

        $this->assertStringContainsString('|aps_', $plain);
        $this->assertSame(hash('sha256', explode('|', $plain, 2)[1]), $token->fresh()->token);

        $this->get(route('settings.edit'))->assertDontSee($plain)->assertDontSee('new-api-token');

        $this->api($plain)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.token.abilities', ['products:read', 'ai:read']);
    }

    public function test_the_plain_token_is_never_flashed_unencrypted(): void
    {
        $this->createToken();

        $this->assertStringNotContainsString('|aps_', (string) session(ApiTokenController::FLASH_KEY));
    }

    public function test_a_token_needs_a_name_and_real_abilities(): void
    {
        $this->post(route('settings.api-tokens.store'), ['abilities' => ['products:read']])->assertSessionHasErrors('name', errorBag: 'apiToken');
        $this->createToken(['abilities' => []])->assertSessionHasErrors('abilities', errorBag: 'apiToken');
        $this->createToken(['abilities' => ['*']])->assertSessionHasErrors('abilities.0', errorBag: 'apiToken');
        $this->createToken(['expires_at' => now()->subDay()->toDateString()])->assertSessionHasErrors('expires_at', errorBag: 'apiToken');

        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_a_token_can_be_revoked_and_stops_working(): void
    {
        $plain = $this->tokenFor($this->user);
        $token = $this->user->tokens()->sole();

        $this->delete(route('settings.api-tokens.destroy', $token->id))->assertRedirect();

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->api($plain)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_another_users_token_cant_be_revoked(): void
    {
        $other = User::factory()->create();
        $this->tokenFor($other);

        $this->delete(route('settings.api-tokens.destroy', $other->tokens()->sole()->id))->assertNotFound();

        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_listing_shows_usage_but_not_the_token(): void
    {
        $plain = $this->tokenFor($this->user);

        $this->api($plain)->getJson('/api/v1/me')->assertOk();
        $this->flushHeaders();
        $this->actingAs($this->user);

        $this->get(route('settings.edit'))
            ->assertSee('Test client')
            ->assertSee('Last Used '.now()->format('M j, Y'))
            ->assertDontSee(explode('|', $plain, 2)[1]);
    }

    public function test_guests_cant_manage_tokens(): void
    {
        $this->app['auth']->forgetGuards();
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        $this->post(route('settings.api-tokens.store'), ['name' => 'x', 'abilities' => ['ai:read']])->assertRedirect(route('login'));
    }

    public function test_token_creation_is_rate_limited(): void
    {
        config(['api.rate_limits.token_creation' => 1]);

        $this->createToken()->assertRedirect();
        $this->createToken()->assertTooManyRequests();

        $this->assertSame(1, $this->user->tokens()->count());
    }

    public function test_documentation_pages_render_for_known_files_only(): void
    {
        $this->get(route('settings.api-docs', 'README.md'))->assertOk()->assertSee('REST API');
        $this->get(route('settings.api-docs', 'authentication.md'))->assertOk();
        $this->get(route('settings.api-docs.openapi'))->assertOk()->assertSee('openapi: 3', false);
        $this->get('/settings/api-docs/..%2F..%2F.env')->assertNotFound();
        $this->get(route('settings.api-docs', 'openapi.txt'))->assertNotFound();
    }
}
