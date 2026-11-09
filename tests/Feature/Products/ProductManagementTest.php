<?php

namespace Tests\Feature\Products;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_a_product_can_be_created(): void
    {
        $this->get(route('products.create'))->assertOk();

        $response = $this->post(route('products.store'), [
            'original_name' => 'Leather Bag',
            'sku' => 'BAG-1',
            'category' => 'Bags',
            'material' => 'Leather',
            'details' => 'Hand-stitched.',
            'status' => 'published',
        ]);

        $product = Product::sole();

        $response->assertRedirect(route('products.show', $product));
        $this->assertSame($this->user->id, $product->user_id);
        $this->assertSame(ProductStatus::Published, $product->status);
        $this->assertSame('BAG-1', $product->sku);
    }

    public function test_a_client_cannot_assign_a_product_to_another_user(): void
    {
        $other = User::factory()->create();

        $this->post(route('products.store'), [
            'original_name' => 'Leather Bag',
            'status' => 'draft',
            'user_id' => $other->id,
        ]);

        $this->assertSame($this->user->id, Product::sole()->user_id);
    }

    public function test_only_the_required_fields_are_needed(): void
    {
        $this->post(route('products.store'), ['original_name' => 'Minimal', 'status' => 'draft'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['original_name' => 'Minimal', 'sku' => null]);
    }

    public function test_creation_is_validated(): void
    {
        $this->post(route('products.store'), [
            'original_name' => '',
            'status' => 'archived',
            'sku' => str_repeat('x', 101),
        ])->assertSessionHasErrors(['original_name', 'status', 'sku']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_sku_must_be_unique_per_user_but_not_globally(): void
    {
        $this->user->products()->create(['original_name' => 'First', 'sku' => 'DUP', 'status' => 'draft']);
        Product::factory()->create(['sku' => 'OTHER-USER']);

        $this->post(route('products.store'), ['original_name' => 'Second', 'sku' => 'DUP', 'status' => 'draft'])
            ->assertSessionHasErrors('sku');

        $this->post(route('products.store'), ['original_name' => 'Third', 'sku' => 'OTHER-USER', 'status' => 'draft'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_product_can_be_edited_and_keep_its_own_sku(): void
    {
        $product = Product::factory()->for($this->user)->create(['sku' => 'KEEP']);

        $this->get(route('products.edit', $product))->assertOk();

        $this->put(route('products.update', $product), [
            'original_name' => 'Renamed',
            'sku' => 'KEEP',
            'status' => 'published',
        ])->assertRedirect(route('products.show', $product));

        $product->refresh();

        $this->assertSame('Renamed', $product->original_name);
        $this->assertSame(ProductStatus::Published, $product->status);
    }

    public function test_editing_is_validated(): void
    {
        $product = Product::factory()->for($this->user)->create(['original_name' => 'Original']);

        $this->put(route('products.update', $product), ['original_name' => '', 'status' => 'draft'])
            ->assertSessionHasErrors('original_name');

        $this->assertSame('Original', $product->fresh()->original_name);
    }

    public function test_a_product_can_be_deleted(): void
    {
        $product = Product::factory()->for($this->user)->create();

        $this->delete(route('products.destroy', $product))->assertRedirect(route('products.index'));

        $this->assertModelMissing($product);
    }

    public function test_the_empty_state_is_shown_without_products(): void
    {
        $this->get(route('products.index'))->assertOk()->assertSee('No products yet');
    }

    public function test_products_can_be_searched_by_name_or_sku(): void
    {
        Product::factory()->for($this->user)->create(['original_name' => 'Leather Bag', 'sku' => 'AAA']);
        Product::factory()->for($this->user)->create(['original_name' => 'Wool Scarf', 'sku' => 'BBB-77']);

        $this->get(route('products.index', ['search' => 'leather']))
            ->assertSee('Leather Bag')->assertDontSee('Wool Scarf');

        $this->get(route('products.index', ['search' => 'BBB']))
            ->assertSee('Wool Scarf')->assertDontSee('Leather Bag');
    }

    public function test_products_can_be_filtered_by_status(): void
    {
        Product::factory()->for($this->user)->create(['original_name' => 'Draft One']);
        Product::factory()->for($this->user)->published()->create(['original_name' => 'Live One']);

        $this->get(route('products.index', ['status' => 'published']))
            ->assertSee('Live One')->assertDontSee('Draft One');
    }

    public function test_products_can_be_sorted_by_creation_date(): void
    {
        Product::factory()->for($this->user)->create(['original_name' => 'Older', 'created_at' => now()->subDay()]);
        Product::factory()->for($this->user)->create(['original_name' => 'Newer']);

        $this->get(route('products.index'))->assertSeeInOrder(['Newer', 'Older']);
        $this->get(route('products.index', ['sort' => 'oldest']))->assertSeeInOrder(['Older', 'Newer']);
    }

    public function test_the_listing_is_paginated_and_keeps_filters(): void
    {
        Product::factory()->for($this->user)->count(12)->create(['status' => 'draft']);

        $response = $this->get(route('products.index', ['status' => 'draft']));

        $this->assertCount(10, $response->viewData('products'));
        $this->assertStringContainsString('status=draft', $response->viewData('products')->nextPageUrl());
    }

    public function test_output_is_escaped(): void
    {
        $product = Product::factory()->for($this->user)->create(['original_name' => '<script>alert(1)</script>']);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_the_ai_action_is_available_on_the_details_page(): void
    {
        $product = Product::factory()->for($this->user)->create();

        $this->get(route('products.show', $product))
            ->assertSee('data-ai-open', false)
            ->assertSee(route('ai.generate', $product), false);
    }
}
