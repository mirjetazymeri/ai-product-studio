<?php

namespace Tests\Feature\Products;

use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductContentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_content_is_saved_for_all_three_languages(): void
    {
        $this->post(route('products.store'), [
            'original_name' => 'Leather Bag',
            'status' => 'draft',
            'contents' => [
                'en' => ['title' => 'Leather Bag', 'meta_title' => 'Buy Leather Bag', 'keywords' => 'bag, leather, bag'],
                'sq' => ['title' => 'Çantë lëkure', 'short_description' => 'Çantë e punuar me dorë'],
                'de' => ['title' => 'Große Ledertasche', 'long_description' => 'Handgefertigt.'],
            ],
        ])->assertSessionHasNoErrors();

        $product = Product::sole();

        $this->assertCount(3, $product->contents);

        $english = $product->contentFor(ContentLanguage::English);
        $this->assertSame('Buy Leather Bag', $english->meta_title);
        $this->assertSame(['bag', 'leather'], $english->keywords);
        $this->assertSame(ContentSource::Manual, $english->source);

        $this->assertSame('cante-lekure', $product->contentFor(ContentLanguage::Albanian)->slug);
        $this->assertSame('grosse-ledertasche', $product->contentFor(ContentLanguage::German)->slug);
    }

    public function test_empty_languages_are_not_stored(): void
    {
        $this->post(route('products.store'), [
            'original_name' => 'Leather Bag',
            'status' => 'draft',
            'contents' => [
                'en' => ['title' => 'Only English'],
                'sq' => ['title' => null, 'keywords' => null],
                'de' => [],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['en'], Product::sole()->contents->map->language->map->value->all());
    }

    public function test_editing_updates_the_existing_record_for_a_language(): void
    {
        $product = Product::factory()->for($this->user)->create();
        ProductContent::factory()->for($product)->create(['title' => 'Old', 'slug' => 'custom-slug']);

        $this->put(route('products.update', $product), [
            'original_name' => $product->original_name,
            'status' => 'draft',
            'contents' => ['en' => ['title' => 'New', 'slug' => 'custom slug!']],
        ])->assertSessionHasNoErrors();

        $content = $product->contents()->sole();

        $this->assertSame('New', $content->title);
        $this->assertSame('custom-slug', $content->slug);
    }

    public function test_clearing_a_language_removes_its_record(): void
    {
        $product = Product::factory()->for($this->user)->create();
        ProductContent::factory()->for($product)->create();

        $this->put(route('products.update', $product), [
            'original_name' => $product->original_name,
            'status' => 'draft',
            'contents' => ['en' => ['title' => null, 'keywords' => null]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('product_contents', 0);
    }

    public function test_an_unsupported_language_is_rejected(): void
    {
        $this->post(route('products.store'), [
            'original_name' => 'Leather Bag',
            'status' => 'draft',
            'contents' => ['fr' => ['title' => 'Sac']],
        ])->assertSessionHasErrors('contents');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_the_content_source_cannot_be_set_from_the_form(): void
    {
        $this->post(route('products.store'), [
            'original_name' => 'Leather Bag',
            'status' => 'draft',
            'contents' => ['en' => ['title' => 'Title', 'source' => 'ai']],
        ])->assertSessionHasErrors('contents.en');

        $this->assertDatabaseCount('product_contents', 0);
    }

    public function test_content_fields_are_validated(): void
    {
        $this->post(route('products.store'), [
            'original_name' => 'Leather Bag',
            'status' => 'draft',
            'contents' => ['de' => ['title' => str_repeat('x', 256), 'meta_description' => str_repeat('x', 501)]],
        ])->assertSessionHasErrors(['contents.de.title', 'contents.de.meta_description']);
    }

    public function test_a_failed_validation_reopens_the_form_with_submitted_keywords(): void
    {
        $product = Product::factory()->for($this->user)->create();

        $this->from(route('products.edit', $product))->followingRedirects()->put(route('products.update', $product), [
            'original_name' => '',
            'status' => 'draft',
            'contents' => ['en' => ['keywords' => 'alpha, beta']],
        ])->assertOk()->assertSee('alpha, beta');
    }

    public function test_the_details_page_shows_content_and_seo_metadata(): void
    {
        $product = Product::factory()->for($this->user)->create();
        ProductContent::factory()->for($product)->create([
            'title' => 'English title',
            'meta_title' => 'English meta',
            'slug' => 'english-slug',
            'keywords' => ['alpha'],
        ]);
        ProductContent::factory()->for($product)->language(ContentLanguage::German)->create(['title' => 'Deutscher Titel']);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee(['English title', 'English meta', 'english-slug', 'alpha', 'Deutscher Titel']);
    }

    public function test_the_database_allows_only_one_record_per_language(): void
    {
        $product = Product::factory()->create();
        ProductContent::factory()->for($product)->create();

        $this->expectException(QueryException::class);

        ProductContent::factory()->for($product)->create();
    }

    public function test_the_service_can_store_ai_content_without_schema_changes(): void
    {
        $product = Product::factory()->for($this->user)->create();

        $content = app(ProductService::class)->saveContent(
            $product,
            ContentLanguage::German,
            ['title' => 'KI Titel'],
            ContentSource::Ai,
        );

        $this->assertSame(ContentSource::Ai, $content->fresh()->source);
    }

    public function test_resaving_unchanged_ai_content_keeps_its_source(): void
    {
        $product = Product::factory()->for($this->user)->create();
        ProductContent::factory()->for($product)->ai()->create(['title' => 'Same', 'keywords' => ['a', 'b']]);

        $original = $product->contents()->sole();

        app(ProductService::class)->saveContent($product, ContentLanguage::English, [
            'title' => 'Same',
            'keywords' => ['a', 'b'],
        ]);

        $this->assertSame(ContentSource::Ai, $original->fresh()->source);
    }
}
