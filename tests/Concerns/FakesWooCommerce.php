<?php

namespace Tests\Concerns;

use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Models\WooCommerceProductLink;
use App\Models\WooCommerceStore;
use App\Services\WooCommerce\Http\HostResolver;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

trait FakesWooCommerce
{
    /** @var array<int, array<string, mixed>> */
    protected array $remoteProducts = [];

    /** Returns a response to replace a write, or null to let the simulated store apply it. */
    protected ?Closure $writeOverride = null;

    /** @var array<string, mixed>|null a canned Gemini response body */
    protected ?array $geminiReply = null;

    /**
     * @param  array<string, list<string>>  $addresses  by host
     */
    protected function fakeDns(array $addresses = []): void
    {
        $this->app->instance(HostResolver::class, new class($addresses) implements HostResolver
        {
            public function __construct(private readonly array $addresses) {}

            public function resolve(string $host): array
            {
                return $this->addresses[$host] ?? ['93.184.216.34'];
            }
        });
    }

    protected function connectedStore(User $user, array $attributes = []): WooCommerceStore
    {
        return $user->wooCommerceStores()->create([
            'name' => 'Test Shop',
            'url' => 'https://shop.example.com',
            'consumer_key' => 'ck_test_key',
            'consumer_secret' => 'cs_test_secret',
            'status' => WooCommerceStore::CONNECTED,
            'content_language' => 'en',
            'capabilities' => [
                'language' => ['provider' => 'none', 'supported' => true, 'default' => null, 'reason' => null],
                'seo' => ['provider' => null, 'name' => null, 'writable' => false, 'reason' => 'No supported SEO plugin was detected.'],
            ],
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function remoteProduct(int $id = 101, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'name' => "Remote Product {$id}",
            'slug' => "remote-product-{$id}",
            'permalink' => "https://shop.example.com/product/remote-product-{$id}",
            'date_modified_gmt' => '2026-11-01T10:00:00',
            'type' => 'simple',
            'status' => 'publish',
            'description' => '<p>Old long description.</p>',
            'short_description' => '<p>Old short description.</p>',
            'sku' => "SKU-{$id}",
            'price' => '19.99',
            'regular_price' => '24.99',
            'sale_price' => '19.99',
            'stock_quantity' => 7,
            'images' => [['id' => 5, 'src' => 'https://shop.example.com/a.jpg']],
            'categories' => [['id' => 1, 'name' => 'Bags', 'slug' => 'bags']],
            'attributes' => [],
            'variations' => [],
            'meta_data' => [['id' => 1, 'key' => '_private', 'value' => 'hidden']],
        ], $overrides);
    }

    /**
     * Simulates a store whose products change when updated.
     *
     * @param  list<array<string, mixed>>  $products
     */
    protected function fakeLiveStore(array $products): void
    {
        $this->remoteProducts = collect($products)->keyBy('id')->all();

        // Laravel keeps the first fake registered, so everything a test needs is handled here.
        Http::fake(function (Request $request, array $options) {
            if ($this->geminiReply !== null && str_contains($request->url(), 'gemini.test')) {
                return Http::response($this->geminiReply);
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (preg_match('#/products/(\d+)$#', $path, $matches) === 1) {
                $id = (int) $matches[1];

                if (! isset($this->remoteProducts[$id])) {
                    return Http::response(['code' => 'woocommerce_rest_product_invalid_id', 'message' => 'Invalid ID.'], 404);
                }

                if ($request->method() === 'PUT') {
                    $override = $this->writeOverride === null ? null : ($this->writeOverride)($request);

                    if ($override !== null) {
                        return $override instanceof Closure ? $override($request, $options) : $override;
                    }

                    $this->remoteProducts[$id] = array_replace($this->remoteProducts[$id], $request->data(), ['date_modified_gmt' => '2026-11-09T12:00:00']);
                }

                return Http::response($this->remoteProducts[$id]);
            }

            if (str_ends_with($path, '/products')) {
                return Http::response(array_values($this->remoteProducts), 200, ['X-WP-TotalPages' => 1]);
            }

            return Http::response(['namespaces' => ['wc/v3']]);
        });
    }

    /**
     * @return Collection<int, Request>
     */
    protected function sentWrites(): Collection
    {
        return collect(Http::recorded(fn (Request $request) => $request->method() !== 'GET'))->map(fn (array $pair) => $pair[0]);
    }

    protected function linkedProduct(User $user, WooCommerceStore $store, int $remoteId = 101, array $linkAttributes = []): WooCommerceProductLink
    {
        $product = Product::factory()->for($user)->create(['details' => 'Some details']);

        return $store->links()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'remote_id' => $remoteId,
            'remote_type' => 'simple',
            'sync_mode' => WooCommerceProductLink::MODE_FULL,
            ...$linkAttributes,
        ]);
    }

    protected function approvedContent(WooCommerceProductLink $link, array $attributes = []): ProductContent
    {
        return ProductContent::factory()->for($link->product)->ai()->create([
            'title' => 'New Title',
            'short_description' => 'New short description.',
            'long_description' => 'New long description.',
            'slug' => 'new-title',
            ...$attributes,
        ]);
    }
}
