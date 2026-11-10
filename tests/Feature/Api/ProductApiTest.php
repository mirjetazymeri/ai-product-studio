<?php

namespace Tests\Feature\Api;

use App\Enums\ContentSource;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Services\AI\ProductContentGenerator;
use App\Services\WooCommerce\Demo\WooCommerceDemoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesWooCommerce;
use Tests\Concerns\UsesApiTokens;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use FakesWooCommerce, RefreshDatabase, UsesApiTokens;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->tokenFor($this->user);
    }

    private function client(): static
    {
        return $this->api($this->token);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->for($this->user)->create($attributes);
    }

    public function test_a_product_can_be_created(): void
    {
        $response = $this->client()->postJson('/api/v1/products', [
            'original_name' => 'Gaming Controller Stand',
            'sku' => 'GCS-1',
            'category' => 'Gaming',
            'material' => 'PLA',
            'details' => 'Holds two controllers.',
        ]);

        $product = $this->user->products()->sole();

        $response->assertCreated()
            ->assertHeader('Location', route('api.v1.products.show', $product))
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Gaming Controller Stand')
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_validation_errors_use_the_documented_shape(): void
    {
        $this->client()->postJson('/api/v1/products', ['sku' => str_repeat('x', 200)])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.original_name.0', 'The product name is required.')
            ->assertJsonStructure(['message', 'errors' => ['original_name', 'sku']]);
    }

    public function test_a_sku_must_be_unique_within_the_users_catalog_only(): void
    {
        $this->product(['sku' => 'SAME']);

        $this->client()->postJson('/api/v1/products', ['original_name' => 'A', 'sku' => 'SAME'])->assertUnprocessable()->assertJsonValidationErrors('sku');

        $this->api($this->tokenFor(User::factory()->create()))->postJson('/api/v1/products', ['original_name' => 'B', 'sku' => 'SAME'])->assertCreated();
    }

    public function test_ownership_cant_be_assigned_by_the_client(): void
    {
        $other = User::factory()->create();

        $this->client()->postJson('/api/v1/products', ['original_name' => 'A', 'user_id' => $other->id, 'demo_store_id' => 1, 'id' => 999])->assertCreated();

        $product = Product::query()->sole();
        $this->assertSame($this->user->id, $product->user_id);
        $this->assertNull($product->demo_store_id);
        $this->assertNotSame(999, $product->id);
    }

    public function test_a_product_can_be_read_updated_and_deleted(): void
    {
        $product = $this->product(['original_name' => 'Old', 'category' => 'Keep']);

        $this->client()->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJsonPath('data.name', 'Old');

        $this->client()->patchJson("/api/v1/products/{$product->id}", ['original_name' => 'New', 'status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.category', 'Keep');

        $this->client()->patchJson("/api/v1/products/{$product->id}", ['status' => 'nonsense'])->assertUnprocessable();

        $this->client()->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();
        $this->assertNull($product->fresh());
    }

    public function test_another_users_product_looks_like_it_doesnt_exist(): void
    {
        $theirs = Product::factory()->for(User::factory()->create())->create(['original_name' => 'Secret']);
        ProductContent::factory()->for($theirs)->create(['language' => 'en']);

        foreach ([
            ['GET', "/api/v1/products/{$theirs->id}"],
            ['PATCH', "/api/v1/products/{$theirs->id}"],
            ['DELETE', "/api/v1/products/{$theirs->id}"],
            ['GET', "/api/v1/products/{$theirs->id}/contents"],
            ['GET', "/api/v1/products/{$theirs->id}/contents/en"],
            ['PUT', "/api/v1/products/{$theirs->id}/contents/en"],
            ['GET', "/api/v1/products/{$theirs->id}/approved-content?language=en"],
            ['POST', "/api/v1/products/{$theirs->id}/generate"],
            ['PUT', "/api/v1/products/{$theirs->id}/external-references/erp"],
            ['DELETE', "/api/v1/products/{$theirs->id}/external-references/erp"],
        ] as [$method, $uri]) {
            $this->client()->json($method, $uri, ['language' => 'en', 'title' => 'x', 'original_name' => 'x', 'external_id' => '1'])
                ->assertNotFound("{$method} {$uri}")
                ->assertDontSee('Secret');
        }

        $this->assertSame('Secret', $theirs->fresh()->original_name);
        $this->client()->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_listing_paginates(): void
    {
        Product::factory()->for($this->user)->count(3)->create();

        $this->client()->getJson('/api/v1/products?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta']);

        $this->client()->getJson('/api/v1/products?per_page=2&page=2')->assertJsonCount(1, 'data');
        $this->client()->getJson('/api/v1/products?per_page=1000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_listing_filters_and_sorts(): void
    {
        $alpha = $this->product(['original_name' => 'Alpha', 'status' => 'published', 'sku' => 'A-1']);
        $zulu = $this->product(['original_name' => 'Zulu', 'status' => 'draft']);
        ProductContent::factory()->for($zulu)->create(['language' => 'sq']);

        $this->client()->getJson('/api/v1/products?search=alph')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alpha->id);
        $this->client()->getJson('/api/v1/products?status=draft')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $zulu->id);
        $this->client()->getJson('/api/v1/products?language=sq')->assertJsonCount(1, 'data')->assertJsonPath('data.0.languages', ['sq']);
        $this->client()->getJson('/api/v1/products?sku=A-1')->assertJsonCount(1, 'data');
        $this->client()->getJson('/api/v1/products?sort=name')->assertJsonPath('data.0.id', $alpha->id);
        $this->client()->getJson('/api/v1/products?sort=-name')->assertJsonPath('data.0.id', $zulu->id);
        $this->client()->getJson('/api/v1/products?sort=password')->assertUnprocessable();
        $this->client()->getJson('/api/v1/products?status=wrong')->assertUnprocessable();
    }

    public function test_demo_products_are_not_part_of_the_api(): void
    {
        Http::fake();
        $store = app(WooCommerceDemoService::class)->activate($this->user);
        $demo = $this->user->products()->getRelated()->newQuery()->forceCreate(['user_id' => $this->user->id, 'demo_store_id' => $store->id, 'original_name' => 'Demo']);

        $this->client()->getJson('/api/v1/products')->assertJsonCount(0, 'data');
        $this->client()->getJson("/api/v1/products/{$demo->id}")->assertNotFound();
    }

    public function test_external_references_are_explicit_and_scoped(): void
    {
        $response = $this->client()->postJson('/api/v1/products', [
            'original_name' => 'Mapped',
            'external_reference' => ['source' => 'erp-main', 'external_id' => 'A100'],
        ])->assertCreated()->assertJsonPath('data.external_references.0.external_id', 'A100');

        $id = $response->json('data.id');

        $this->client()->getJson('/api/v1/products?external_source=erp-main&external_id=A100')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->client()->getJson('/api/v1/products?external_source=other&external_id=A100')->assertJsonCount(0, 'data');
        $this->client()->getJson('/api/v1/products?external_id=A100')->assertUnprocessable();

        // The same ID may exist in another source, or for another user.
        $this->client()->putJson("/api/v1/products/{$id}/external-references/shop-two", ['external_id' => 'A100'])->assertOk();
        $this->api($this->tokenFor(User::factory()->create()))->postJson('/api/v1/products', [
            'original_name' => 'Theirs',
            'external_reference' => ['source' => 'erp-main', 'external_id' => 'A100'],
        ])->assertCreated();

        $other = $this->product();

        $this->client()->putJson("/api/v1/products/{$other->id}/external-references/erp-main", ['external_id' => 'A100'])
            ->assertConflict()
            ->assertJsonPath('code', 'external_reference_conflict')
            ->assertJsonPath('product_id', $id);

        $this->client()->postJson('/api/v1/products', ['original_name' => 'Dup', 'external_reference' => ['source' => 'erp-main', 'external_id' => 'A100']])->assertConflict();
        $this->assertSame(0, $this->user->products()->where('original_name', 'Dup')->count());

        $this->client()->deleteJson("/api/v1/products/{$id}/external-references/erp-main")->assertNoContent();
        $this->assertNotNull(Product::query()->find($id));
    }

    public function test_store_integration_references_cant_be_touched(): void
    {
        $product = $this->product();

        $this->client()->postJson('/api/v1/products', ['original_name' => 'X', 'external_reference' => ['source' => 'woocommerce-1', 'external_id' => '5']])
            ->assertUnprocessable()->assertJsonValidationErrors('external_reference.source');
        $this->client()->putJson("/api/v1/products/{$product->id}/external-references/woocommerce-1", ['external_id' => '5'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_external_source');
        $this->client()->deleteJson("/api/v1/products/{$product->id}/external-references/woocommerce-1")->assertUnprocessable();
    }

    public function test_deleting_a_linked_product_needs_explicit_unlinking_and_never_calls_the_store(): void
    {
        Http::fake();
        $store = $this->connectedStore($this->user);
        $link = $this->linkedProduct($this->user, $store);

        $this->client()->deleteJson("/api/v1/products/{$link->product_id}")->assertConflict()->assertJsonPath('code', 'linked_to_store');
        $this->assertNotNull($link->product->fresh());

        $this->client()->deleteJson("/api/v1/products/{$link->product_id}?unlink_stores=true")->assertNoContent();

        $this->assertNull($link->product->fresh());
        $this->assertNotNull($store->fresh());
        Http::assertNothingSent();
    }

    public function test_store_credentials_never_appear(): void
    {
        $store = $this->connectedStore($this->user);
        $link = $this->linkedProduct($this->user, $store);

        $this->client()->getJson("/api/v1/products/{$link->product_id}")
            ->assertOk()
            ->assertDontSee('ck_test_key')
            ->assertDontSee('cs_test_secret');
    }

    public function test_multilingual_content_can_be_read(): void
    {
        $product = $this->product();
        ProductContent::factory()->for($product)->create(['language' => 'en', 'title' => 'Hello']);
        ProductContent::factory()->for($product)->create(['language' => 'de', 'title' => 'Hallo']);

        $this->client()->getJson("/api/v1/products/{$product->id}/contents")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.language', 'de');
        $this->client()->getJson("/api/v1/products/{$product->id}/contents/de")->assertOk()->assertJsonPath('data.title', 'Hallo');
        $this->client()->getJson("/api/v1/products/{$product->id}/contents/sq")->assertNotFound();
        $this->client()->getJson("/api/v1/products/{$product->id}/contents/xx")->assertNotFound();
    }

    public function test_manual_content_can_be_created_and_replaced(): void
    {
        $product = $this->product();
        $uri = "/api/v1/products/{$product->id}/contents/en";

        $this->client()->putJson($uri, ['title' => 'Çantë e Re', 'short_description' => 'Short', 'keywords' => ['a', 'b']])
            ->assertCreated()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.approved', true)
            ->assertJsonPath('data.slug', 'cante-e-re');

        // PUT replaces the record, so a field left out is cleared.
        $this->client()->putJson($uri, ['title' => 'Second'])->assertOk()->assertJsonPath('data.short_description', null);

        $this->client()->putJson($uri, ['keywords' => 'not-a-list'])->assertUnprocessable();
        $this->client()->putJson($uri, [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame('Second', $product->contents()->sole()->title);
    }

    public function test_approved_ai_content_isnt_overwritten_without_confirmation(): void
    {
        $product = $this->product();
        ProductContent::factory()->for($product)->ai()->create(['language' => 'en', 'title' => 'Approved AI']);
        $uri = "/api/v1/products/{$product->id}/contents/en";

        $this->client()->putJson($uri, ['title' => 'Manual'])->assertConflict()->assertJsonPath('code', 'content_exists');
        $this->assertSame('Approved AI', $product->contents()->sole()->title);

        $this->client()->putJson($uri, ['title' => 'Manual', 'confirm_overwrite' => true])->assertOk()->assertJsonPath('data.source', 'manual');
    }

    public function test_approved_content_only_returns_approved_sources(): void
    {
        $product = $this->product();
        ProductContent::factory()->for($product)->ai()->create(['language' => 'en', 'title' => 'Approved']);
        ProductContent::factory()->for($product)->create(['language' => 'de', 'title' => 'Imported', 'source' => ContentSource::WooCommerce]);
        $uri = "/api/v1/products/{$product->id}/approved-content";

        $this->client()->getJson("{$uri}?language=en")->assertOk()->assertJsonPath('data.title', 'Approved')->assertJsonPath('data.approved', true);
        $this->client()->getJson("{$uri}?language=de")->assertNotFound()->assertJsonPath('code', 'no_approved_content')->assertDontSee('Imported');
        $this->client()->getJson("{$uri}?language=sq")->assertNotFound();
        $this->client()->getJson($uri)->assertUnprocessable()->assertJsonValidationErrors('language');
    }

    public function test_seo_analysis_is_deterministic_and_makes_no_ai_call(): void
    {
        Http::fake();
        $this->mock(ProductContentGenerator::class)->shouldNotReceive('generate');

        $body = ['product_name' => 'Tote', 'title' => 'Leather Tote Bag for Daily Use', 'meta_title' => 'Short', 'slug' => 'leather-tote', 'keywords' => ['leather tote']];

        $first = $this->client()->postJson('/api/v1/seo/analyze', $body)
            ->assertOk()
            ->assertJsonStructure(['data' => ['score', 'field_feedback' => [['key', 'label', 'field', 'points', 'max', 'status', 'message']], 'warnings', 'suggestions']]);

        $this->assertSame($first->json(), $this->client()->postJson('/api/v1/seo/analyze', $body)->json());
        $this->assertGreaterThan(0, $first->json('data.score'));

        $this->client()->postJson('/api/v1/seo/analyze', [])->assertUnprocessable();
        Http::assertNothingSent();
    }
}
