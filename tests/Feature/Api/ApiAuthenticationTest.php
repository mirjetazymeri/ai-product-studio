<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesApiTokens;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase, UsesApiTokens;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_me_returns_only_non_sensitive_details(): void
    {
        $response = $this->api($this->tokenFor($this->user, ['products:read']))->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.token.abilities', ['products:read'])
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
        $response->assertHeader('X-API-Version', 'v1');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_request_without_a_token_is_rejected_as_json(): void
    {
        $this->guest()->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'A valid API token is required.', 'code' => 'unauthenticated']);

        // Even without an Accept header, an API client never gets a login redirect.
        $this->guest()->get('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        $this->api('1|not-a-real-token')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $token = $this->tokenFor($this->user, null, now()->addHour());

        $this->api($token)->getJson('/api/v1/me')->assertOk();

        $this->travel(2)->hours();

        $this->api($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_browser_session_cant_use_the_api(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/me')->assertUnauthorized();
        $this->actingAs($this->user)->getJson('/api/v1/products')->assertUnauthorized();
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function protectedEndpoints(): array
    {
        return [
            'list products' => ['GET', '/api/v1/products', ['products:read']],
            'create product' => ['POST', '/api/v1/products', ['products:write']],
            'read product' => ['GET', '/api/v1/products/{product}', ['products:read']],
            'update product' => ['PATCH', '/api/v1/products/{product}', ['products:write']],
            'delete product' => ['DELETE', '/api/v1/products/{product}', ['products:write']],
            'map external id' => ['PUT', '/api/v1/products/{product}/external-references/erp', ['products:write']],
            'unmap external id' => ['DELETE', '/api/v1/products/{product}/external-references/erp', ['products:write']],
            'list contents' => ['GET', '/api/v1/products/{product}/contents', ['content:read']],
            'read content' => ['GET', '/api/v1/products/{product}/contents/en', ['content:read']],
            'write content' => ['PUT', '/api/v1/products/{product}/contents/en', ['content:write']],
            'approved content' => ['GET', '/api/v1/products/{product}/approved-content?language=en', ['content:read']],
            'seo' => ['POST', '/api/v1/seo/analyze', ['content:read']],
            'analyze' => ['POST', '/api/v1/ai/analyze-product', ['ai:generate']],
            'generate' => ['POST', '/api/v1/products/{product}/generate', ['ai:generate']],
            'read generation' => ['GET', '/api/v1/generations/1', ['ai:read']],
            'create export' => ['POST', '/api/v1/exports', ['exports:read']],
            'read export' => ['GET', '/api/v1/exports/1', ['exports:read']],
            'download export' => ['GET', '/api/v1/exports/1/download', ['exports:read']],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_each_endpoint_requires_its_ability(string $method, string $uri, array $required): void
    {
        $product = Product::factory()->for($this->user)->create();
        $uri = str_replace('{product}', (string) $product->id, $uri);
        $others = array_values(array_diff(ApiAbility::values(), $required));

        $this->api($this->tokenFor($this->user, $others))
            ->json($method, $uri)
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_ability')
            ->assertJsonPath('required_abilities', $required);

        $this->assertNotNull($product->fresh());
    }

    public function test_every_api_route_has_authentication_and_an_ability_or_is_me(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'));

        $this->assertGreaterThan(15, $routes->count());

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, $route->uri());

            if ($route->uri() !== 'api/v1/me') {
                $this->assertNotEmpty(array_filter($middleware, fn ($name) => is_string($name) && str_starts_with($name, 'abilities:')), $route->uri());
            }
        }
    }

    public function test_repeated_failed_authentication_is_slowed_down(): void
    {
        config(['api.rate_limits.failed_auth' => 2]);

        $this->api('1|wrong')->getJson('/api/v1/me')->assertUnauthorized();
        $this->api('1|wrong')->getJson('/api/v1/me')->assertUnauthorized();

        $this->api('1|wrong')->getJson('/api/v1/me')->assertTooManyRequests()->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    }

    public function test_https_is_required_when_configured(): void
    {
        config(['api.require_https' => true]);
        $token = $this->tokenFor($this->user);

        $this->api($token)->getJson('http://localhost/api/v1/me')->assertForbidden()->assertJsonPath('code', 'https_required');
        $this->api($token)->getJson('https://localhost/api/v1/me')->assertOk();
    }

    public function test_malformed_json_is_a_400(): void
    {
        $this->api($this->tokenFor($this->user))
            ->call('POST', '/api/v1/products', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($this->user), 'CONTENT_TYPE' => 'application/json'], '{"original_name": ')
            ->assertBadRequest()
            ->assertJsonPath('code', 'malformed_json');
    }

    public function test_not_found_and_wrong_method_are_structured_and_reveal_nothing(): void
    {
        $token = $this->tokenFor($this->user);

        $missing = $this->api($token)->getJson('/api/v1/products/999999')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->assertStringNotContainsString('App\\Models', $missing->getContent());

        $this->api($token)->getJson('/api/v1/nothing-here')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->api($token)->putJson('/api/v1/me')->assertMethodNotAllowed()->assertJsonPath('code', 'method_not_allowed');
    }

    public function test_unexpected_errors_reveal_nothing(): void
    {
        config(['app.debug' => true]);

        $this->mock(ProductService::class)
            ->shouldReceive('create')
            ->andThrow(new RuntimeException('SQLSTATE secret at C:\\laragon\\www\\path'));

        $response = $this->api($this->tokenFor($this->user))->postJson('/api/v1/products', ['original_name' => 'X']);

        $response->assertServerError()->assertExactJson(['message' => 'Something went wrong on our side.', 'code' => 'server_error']);
    }

    public function test_general_requests_are_rate_limited(): void
    {
        config(['api.rate_limits.general' => 2]);
        $token = $this->tokenFor($this->user);

        $this->api($token)->getJson('/api/v1/me')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->api($token)->getJson('/api/v1/me')->assertOk();

        $this->api($token)->getJson('/api/v1/me')
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'rate_limited')
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'code', 'retry_after']);

        // One client's limit is its own.
        $this->api($this->tokenFor(User::factory()->create()))->getJson('/api/v1/me')->assertOk();
    }

    public function test_the_web_app_still_redirects_guests_to_login(): void
    {
        $this->guest()->get('/products')->assertRedirect(route('login'));
    }
}
