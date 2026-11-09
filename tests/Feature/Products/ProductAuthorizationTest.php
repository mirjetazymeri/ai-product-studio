<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Product $foreignProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->foreignProduct = Product::factory()->create(['original_name' => 'Secret Product']);
        ProductContent::factory()->for($this->foreignProduct)->create(['title' => 'Secret Title']);

        $this->actingAs(User::factory()->create());
    }

    public function test_a_user_cannot_view_another_users_product(): void
    {
        $this->get(route('products.show', $this->foreignProduct))
            ->assertForbidden()
            ->assertDontSee('Secret Title');
    }

    public function test_a_user_cannot_open_the_edit_form_of_another_users_product(): void
    {
        $this->get(route('products.edit', $this->foreignProduct))->assertForbidden();
    }

    public function test_a_user_cannot_update_another_users_product_or_its_content(): void
    {
        $this->put(route('products.update', $this->foreignProduct), [
            'original_name' => 'Hijacked',
            'status' => 'draft',
            'contents' => ['en' => ['title' => 'Hijacked']],
        ])->assertForbidden();

        $this->assertSame('Secret Product', $this->foreignProduct->fresh()->original_name);
        $this->assertSame('Secret Title', $this->foreignProduct->contents()->sole()->title);
    }

    public function test_a_user_cannot_delete_another_users_product(): void
    {
        $this->delete(route('products.destroy', $this->foreignProduct))->assertForbidden();

        $this->assertModelExists($this->foreignProduct);
    }

    public function test_the_listing_only_contains_the_users_own_products(): void
    {
        $this->get(route('products.index'))->assertDontSee('Secret Product');
    }

    public function test_search_cannot_reach_another_users_products(): void
    {
        $this->foreignProduct->update(['sku' => 'SECRET-SKU']);

        $this->get(route('products.index', ['search' => 'SECRET']))
            ->assertOk()
            ->assertDontSee('Secret Product');
    }

    public function test_the_dashboard_only_counts_the_users_own_products(): void
    {
        $response = $this->get(route('dashboard'))->assertOk()->assertDontSee('Secret Product');

        $this->assertSame(0, $response->viewData('stats')['total']);
    }
}
