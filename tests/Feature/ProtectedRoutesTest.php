<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProtectedRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            'dashboard' => ['get', '/dashboard'],
            'products index' => ['get', '/products'],
            'products create' => ['get', '/products/create'],
            'products store' => ['post', '/products'],
            'ai studio' => ['get', '/ai-studio'],
            'generation history' => ['get', '/generation-history'],
            'settings' => ['get', '/settings'],
            'settings profile' => ['patch', '/settings/profile'],
            'settings password' => ['put', '/settings/password'],
            'logout' => ['post', '/logout'],
            'ai generate' => ['post', '/ai/products/1/generate'],
            'ai save' => ['post', '/ai/generations/1/save'],
            'ai review' => ['post', '/ai/generations/1/review'],
            'catalog import upload' => ['post', '/catalog/imports'],
            'catalog export create' => ['post', '/catalog/exports'],
            'catalog export download' => ['get', '/catalog/exports/1/download'],
            'bulk generation start' => ['post', '/ai/bulk-generations'],
            'content review' => ['get', '/ai/reviews'],
            'content review approve' => ['post', '/ai/reviews/approve'],
            'integrations' => ['get', '/integrations'],
            'store connect' => ['post', '/integrations/woocommerce'],
            'store test' => ['post', '/integrations/woocommerce/stores/1/test'],
            'store sync confirm' => ['post', '/integrations/woocommerce/sync/1/confirm'],
            'ai product analysis' => ['post', '/ai/products/analyze'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_guests_are_sent_to_login(string $method, string $uri): void
    {
        $this->{$method}($uri)->assertRedirect(route('login'));
    }

    public function test_guests_cannot_reach_an_existing_product(): void
    {
        $product = Product::factory()->create();

        $this->get(route('products.show', $product))->assertRedirect(route('login'));
        $this->get(route('products.edit', $product))->assertRedirect(route('login'));
        $this->put(route('products.update', $product))->assertRedirect(route('login'));
        $this->delete(route('products.destroy', $product))->assertRedirect(route('login'));

        $this->assertModelExists($product);
    }
}
