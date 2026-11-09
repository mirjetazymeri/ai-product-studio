<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_has_many_products_and_a_product_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();

        $this->assertTrue($user->products->first()->is($product));
        $this->assertTrue($product->user->is($user));
    }

    public function test_a_product_has_many_contents_and_a_content_belongs_to_a_product(): void
    {
        $product = Product::factory()->create();
        $content = ProductContent::factory()->for($product)->create();

        $this->assertTrue($product->contents->first()->is($content));
        $this->assertTrue($content->product->is($product));
    }

    public function test_a_user_reaches_content_through_their_products(): void
    {
        $user = User::factory()->create();
        $content = ProductContent::factory()->for(Product::factory()->for($user))->create();
        ProductContent::factory()->create();

        $this->assertTrue($user->productContents()->sole()->is($content));
    }

    public function test_deleting_a_product_deletes_its_contents(): void
    {
        $content = ProductContent::factory()->create();

        $content->product->delete();

        $this->assertModelMissing($content);
    }

    public function test_deleting_a_user_deletes_their_products(): void
    {
        $product = Product::factory()->create();

        $product->user->delete();

        $this->assertModelMissing($product);
    }

    public function test_keywords_are_cast_to_an_array(): void
    {
        $content = ProductContent::factory()->create(['keywords' => ['one', 'two']]);

        $this->assertSame(['one', 'two'], $content->fresh()->keywords);
    }
}
