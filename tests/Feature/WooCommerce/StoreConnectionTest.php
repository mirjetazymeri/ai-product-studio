<?php

namespace Tests\Feature\WooCommerce;

use App\Enums\WooCommerceError;
use App\Models\ProductContent;
use App\Models\User;
use App\Models\WooCommerceStore;
use App\Models\WooCommerceSyncItem;
use App\Models\WooCommerceSyncRun;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceConnectionService;
use App\Services\WooCommerce\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesWooCommerce;
use Tests\TestCase;

class StoreConnectionTest extends TestCase
{
    use FakesWooCommerce, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDns();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function connect(array $data = [])
    {
        return $this->post(route('integrations.store'), [
            'name' => 'My Shop',
            'url' => 'https://shop.example.com/',
            'consumer_key' => 'ck_abc123',
            'consumer_secret' => 'cs_supersecret',
            ...$data,
        ]);
    }

    public function test_the_integrations_page_has_an_empty_state_and_sidebar_link(): void
    {
        $this->get(route('integrations.index'))->assertOk()->assertSee(['Integrations', 'No stores connected', 'Connect WooCommerce Store']);
    }

    public function test_a_store_connects_and_is_tested_with_read_only_requests(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        $this->connect()->assertRedirect(route('integrations.index'))->assertSessionHas('status');

        $store = WooCommerceStore::sole();

        $this->assertSame(WooCommerceStore::CONNECTED, $store->status);
        $this->assertSame('https://shop.example.com', $store->url);
        $this->assertNotNull($store->last_checked_at);
        $this->assertCount(0, $this->sentWrites());
    }

    public function test_credentials_are_encrypted_at_rest_and_sent_only_by_header(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        $this->connect();

        $raw = DB::table('woocommerce_stores')->first();

        $this->assertStringNotContainsString('cs_supersecret', $raw->consumer_secret);
        $this->assertStringNotContainsString('ck_abc123', $raw->consumer_key);
        $this->assertSame('cs_supersecret', WooCommerceStore::sole()->consumer_secret);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/wp-json/wc/v3/products')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('ck_abc123:cs_supersecret'))
            && ! str_contains($request->url(), 'cs_supersecret')
            && ! str_contains($request->url(), 'ck_abc123'));
    }

    public function test_the_secret_is_never_shown_or_serialized(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);
        $this->connect();

        $store = WooCommerceStore::sole();

        $this->get(route('integrations.index'))->assertDontSee('cs_supersecret')->assertDontSee('ck_abc123');
        $this->get(route('integrations.edit', $store))->assertDontSee('cs_supersecret')->assertDontSee('ck_abc123');
        $this->assertArrayNotHasKey('consumer_secret', $store->toArray());
        $this->assertArrayNotHasKey('consumer_key', $store->toArray());
    }

    public function test_credentials_are_never_flashed_to_the_session(): void
    {
        Http::fake();

        $this->connect(['name' => ''])->assertSessionHasErrors('name');
        $this->assertArrayNotHasKey('consumer_secret', session()->getOldInput());
        $this->assertArrayNotHasKey('consumer_key', session()->getOldInput());

        $this->connect(['url' => 'http://insecure.example.com'])->assertSessionHasErrors('url');
        $this->assertArrayNotHasKey('consumer_secret', session()->getOldInput());
        $this->assertArrayNotHasKey('consumer_key', session()->getOldInput());
    }

    /**
     * @return array<string, array{int, array<string, mixed>, WooCommerceError}>
     */
    public static function failureResponses(): array
    {
        return [
            'invalid credentials' => [401, ['code' => 'woocommerce_rest_authentication_error'], WooCommerceError::InvalidCredentials],
            'forbidden' => [403, ['code' => 'rest_forbidden'], WooCommerceError::Forbidden],
            'no api' => [404, ['code' => 'rest_no_route'], WooCommerceError::MissingApi],
            'unavailable' => [503, [], WooCommerceError::Unavailable],
            'redirected' => [301, [], WooCommerceError::Redirected],
        ];
    }

    #[DataProvider('failureResponses')]
    public function test_connection_failures_get_clear_messages(int $status, array $body, WooCommerceError $expected): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        $this->connect()->assertSessionHasErrors(['connection' => $expected->message()]);

        $store = WooCommerceStore::sole();

        $this->assertSame(WooCommerceStore::FAILING, $store->status);
        $this->assertSame($expected->value, $store->last_error_code);
    }

    /**
     * @return array<string, array{string, WooCommerceError}>
     */
    public static function connectionFailures(): array
    {
        return [
            'timeout' => ['cURL error 28: Operation timed out', WooCommerceError::Timeout],
            'certificate' => ['cURL error 60: SSL certificate problem', WooCommerceError::SslError],
            'network' => ['cURL error 7: Failed to connect', WooCommerceError::NetworkError],
        ];
    }

    #[DataProvider('connectionFailures')]
    public function test_connection_problems_get_clear_messages(string $curlError, WooCommerceError $expected): void
    {
        Http::fake(['*' => Http::failedConnection($curlError)]);

        $this->connect()->assertSessionHasErrors(['connection' => $expected->message()]);
    }

    public function test_a_web_page_instead_of_the_api_is_reported(): void
    {
        Http::fake(['*' => Http::response('<html>Hello</html>', 200)]);

        $this->connect()->assertSessionHasErrors(['connection' => WooCommerceError::MissingApi->message()]);
    }

    public function test_redirects_are_refused_tls_is_verified_and_the_address_is_pinned(): void
    {
        $options = null;

        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options ??= $sent;

            return Http::response([$this->remoteProduct()], 200, ['X-WP-TotalPages' => 1]);
        });

        $this->connect();

        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['verify']);
        $this->assertSame(['shop.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('https', $options['curl'][CURLOPT_PROTOCOLS_STR]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'plain http' => ['http://shop.example.com'],
            'localhost' => ['https://localhost'],
            'localhost subdomain' => ['https://shop.localhost'],
            'local suffix' => ['https://shop.local'],
            'internal suffix' => ['https://shop.internal'],
            'laragon test domain' => ['https://ai-product-studio.test'],
            'loopback ip' => ['https://127.0.0.1'],
            'private ip' => ['https://10.0.0.5'],
            'metadata ip' => ['https://169.254.169.254'],
            'decimal ip' => ['https://2130706433'],
            'hex ip' => ['https://0x7f.0x0.0x0.0x1'],
            'ipv6 loopback' => ['https://[::1]'],
            'credentials in url' => ['https://user:pass@shop.example.com'],
            'custom port' => ['https://shop.example.com:8443'],
            'query string' => ['https://shop.example.com?x=1'],
            'not a url' => ['shop'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_are_rejected_without_any_request(string $url): void
    {
        Http::fake();

        $this->connect(['url' => $url])->assertSessionHasErrors('url');

        Http::assertNothingSent();
        $this->assertDatabaseCount('woocommerce_stores', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function privateAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10' => ['10.1.2.3'],
            'private 172' => ['172.16.0.1'],
            'private 192' => ['192.168.1.1'],
            'link local' => ['169.254.10.10'],
            'aws metadata' => ['169.254.169.254'],
            'carrier nat' => ['100.64.0.1'],
            'azure metadata' => ['168.63.129.16'],
            'ipv6 loopback' => ['::1'],
            'ipv6 private' => ['fd00::1'],
            'ipv6 link local' => ['fe80::1'],
            'ipv4 mapped' => ['::ffff:127.0.0.1'],
        ];
    }

    #[DataProvider('privateAddresses')]
    public function test_public_names_resolving_to_private_addresses_are_rejected(string $address): void
    {
        Http::fake();
        $this->fakeDns(['shop.example.com' => [$address]]);

        $this->connect()->assertSessionHasErrors(['url' => WooCommerceError::UnsafeAddress->message()]);

        Http::assertNothingSent();
    }

    public function test_one_private_address_among_public_ones_is_rejected(): void
    {
        Http::fake();
        $this->fakeDns(['shop.example.com' => ['93.184.216.34', '10.0.0.1']]);

        $this->connect()->assertSessionHasErrors('url');
    }

    public function test_dns_rebinding_after_connecting_is_caught_on_the_next_request(): void
    {
        Http::fake();
        $store = $this->connectedStore($this->user);
        $this->fakeDns(['shop.example.com' => ['127.0.0.1']]);

        $failure = app(WooCommerceConnectionService::class)->test($store);

        $this->assertSame(WooCommerceError::UnsafeAddress, $failure->error);
        Http::assertNothingSent();
    }

    public function test_valid_domains_with_hex_like_letters_are_accepted(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        $this->connect(['url' => 'https://abc.de'])->assertSessionDoesntHaveErrors('url');
    }

    public function test_a_store_in_a_subfolder_keeps_its_path_and_loses_a_pasted_api_path(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        $this->connect(['url' => 'https://example.com/shop/wp-json/wc/v3/']);

        $this->assertSame('https://example.com/shop', WooCommerceStore::sole()->url);
    }

    public function test_multiple_stores_are_supported_but_not_the_same_one_twice(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        $this->connect();
        $this->connect(['url' => 'https://other.example.com']);
        $this->connect()->assertSessionHasErrors('url');

        $this->assertDatabaseCount('woocommerce_stores', 2);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->connect(['name' => '', 'consumer_key' => '', 'consumer_secret' => ''])
            ->assertSessionHasErrors(['name', 'consumer_key', 'consumer_secret']);
    }

    public function test_editing_without_new_credentials_keeps_the_saved_ones(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);
        $store = $this->connectedStore($this->user);

        $this->put(route('integrations.update', $store), ['name' => 'Renamed', 'url' => $store->url])->assertRedirect();

        $store->refresh();

        $this->assertSame('Renamed', $store->name);
        $this->assertSame('cs_test_secret', $store->consumer_secret);
    }

    public function test_one_credential_without_the_other_is_rejected(): void
    {
        $store = $this->connectedStore($this->user);

        $this->put(route('integrations.update', $store), ['name' => 'X', 'url' => $store->url, 'consumer_key' => 'ck_new'])
            ->assertSessionHasErrors('consumer_secret');
    }

    public function test_the_address_cannot_change_while_products_are_linked(): void
    {
        $store = $this->connectedStore($this->user);
        $this->linkedProduct($this->user, $store);

        $this->put(route('integrations.update', $store), ['name' => 'X', 'url' => 'https://other.example.com'])
            ->assertSessionHasErrors('url');
    }

    public function test_stores_are_protected_from_other_users(): void
    {
        Http::fake();
        $store = $this->connectedStore($this->user);
        $this->actingAs(User::factory()->create());

        $this->get(route('integrations.edit', $store))->assertForbidden();
        $this->get(route('integrations.products', $store))->assertForbidden();
        $this->get(route('integrations.imports.create', $store))->assertForbidden();
        $this->post(route('integrations.test', $store))->assertForbidden();
        $this->post(route('integrations.disconnect', $store), ['confirm' => '1'])->assertForbidden();
        $this->post(route('integrations.imports.store', $store), ['language' => 'en'])->assertForbidden();
        $this->post(route('integrations.sync.store', $store), ['language' => 'en', 'link_ids' => [1]])->assertForbidden();
        $this->get(route('integrations.index'))->assertDontSee('Test Shop');

        Http::assertNothingSent();
        $this->assertSame('cs_test_secret', $store->fresh()->consumer_secret);
    }

    public function test_guests_cannot_reach_integrations(): void
    {
        auth()->logout();

        $this->get(route('integrations.index'))->assertRedirect(route('login'));
        $this->get(route('integrations.sync.history'))->assertRedirect(route('login'));
    }

    public function test_disconnect_requires_confirmation(): void
    {
        $store = $this->connectedStore($this->user);

        $this->post(route('integrations.disconnect', $store))->assertSessionHasErrors('confirm');

        $this->assertSame(WooCommerceStore::CONNECTED, $store->fresh()->status);
    }

    public function test_disconnect_removes_credentials_and_keeps_local_data_without_contacting_the_store(): void
    {
        Http::fake();
        $store = $this->connectedStore($this->user);
        $link = $this->linkedProduct($this->user, $store);
        $content = $this->approvedContent($link);

        $this->post(route('integrations.disconnect', $store), ['confirm' => '1'])->assertSessionHas('status');

        $raw = DB::table('woocommerce_stores')->first();

        $this->assertNull($raw->consumer_key);
        $this->assertNull($raw->consumer_secret);
        $this->assertSame(WooCommerceStore::DISCONNECTED, $store->fresh()->status);
        $this->assertModelExists($link);
        $this->assertModelExists($link->product);
        $this->assertModelExists(ProductContent::find($content->id));
        Http::assertNothingSent();
    }

    public function test_disconnect_stops_pending_work_and_keeps_history(): void
    {
        $store = $this->connectedStore($this->user);
        $link = $this->linkedProduct($this->user, $store);

        $run = $this->user->wooCommerceSyncRuns()->create(['woocommerce_store_id' => $store->id, 'language' => 'en', 'status' => WooCommerceSyncRun::QUEUED]);
        $item = $run->items()->create(['woocommerce_product_link_id' => $link->id, 'product_id' => $link->product_id, 'remote_id' => 101, 'product_name' => 'X', 'language' => 'en', 'status' => WooCommerceSyncItem::QUEUED]);

        $this->post(route('integrations.disconnect', $store), ['confirm' => '1']);

        $this->assertSame(WooCommerceSyncRun::FAILED, $run->fresh()->status);
        $this->assertSame(WooCommerceSyncItem::FAILED, $item->fresh()->status);
        $this->assertSame(WooCommerceError::Disconnected->value, $item->fresh()->error_code);
    }

    public function test_a_disconnected_store_cannot_be_contacted(): void
    {
        Http::fake();
        $store = $this->connectedStore($this->user);

        app(WooCommerceConnectionService::class)->disconnect($store);

        $this->post(route('integrations.imports.store', $store), ['language' => 'en'])->assertSessionHasErrors('connection');

        Http::assertNothingSent();
    }

    public function test_failures_never_log_credentials_or_bodies(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['code' => 'bad', 'message' => 'cs_test_secret leaked'], 500)]);

        app(WooCommerceConnectionService::class)->test($this->connectedStore($this->user));

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            $encoded = json_encode($context);

            $this->assertStringNotContainsString('cs_test_secret', $encoded);
            $this->assertStringNotContainsString('ck_test_key', $encoded);
            $this->assertStringNotContainsString('shop.example.com', $encoded);

            return true;
        });
    }

    public function test_the_store_is_throttled_per_minute(): void
    {
        config(['woocommerce.requests_per_minute' => 1]);
        $this->fakeLiveStore([$this->remoteProduct()]);
        $store = $this->connectedStore($this->user);
        $service = app(WooCommerceService::class);

        $service->getProduct($store, 101);

        try {
            $service->getProduct($store, 101);
            $this->fail('Expected the store to be throttled.');
        } catch (WooCommerceException $exception) {
            $this->assertSame(WooCommerceError::RateLimited, $exception->error);
        }

        Http::assertSentCount(1);
    }

    public function test_reads_retry_transient_errors_within_a_strict_limit(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response([], 503)]);

        $store = $this->connectedStore($this->user);

        app(WooCommerceConnectionService::class)->test($store);

        Http::assertSentCount(2);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string, bool, ?string}>
     */
    public static function capabilityCases(): array
    {
        $noLang = [];
        $wpmlFields = ['lang' => 'de', 'translations' => ['en' => 202]];

        return [
            'plain store' => [['active_plugins' => []], $noLang, 'none', true, null],
            'wpml with data' => [['active_plugins' => [['plugin' => 'sitepress-multilingual-cms/sitepress.php']]], $wpmlFields, 'wpml', true, 'de'],
            'wpml without fields' => [['active_plugins' => [['plugin' => 'sitepress-multilingual-cms/sitepress.php']]], $noLang, 'wpml', false, null],
            'polylang' => [['active_plugins' => [['plugin' => 'polylang/polylang.php']]], $noLang, 'polylang', false, null],
            'translatepress' => [['active_plugins' => [['plugin' => 'translatepress-multilingual/index.php']]], $noLang, 'translatepress', false, null],
        ];
    }

    #[DataProvider('capabilityCases')]
    public function test_multilingual_capability_detection(array $status, array $productFields, string $provider, bool $supported, ?string $default): void
    {
        Http::fake(function (Request $request) use ($status, $productFields) {
            return match (true) {
                str_contains($request->url(), 'system_status') => Http::response($status),
                str_contains($request->url(), '/products') => Http::response([$this->remoteProduct(101, $productFields)], 200, ['X-WP-TotalPages' => 1]),
                default => Http::response(['namespaces' => ['wc/v3']]),
            };
        });

        $store = $this->connectedStore($this->user, ['capabilities' => null, 'content_language' => null]);

        app(WooCommerceConnectionService::class)->test($store);

        $language = $store->fresh()->capabilities['language'];

        $this->assertSame($provider, $language['provider']);
        $this->assertSame($supported, $language['supported']);
        $this->assertSame($default, $language['default']);
    }

    public function test_plugins_are_detected_from_namespaces_when_status_is_forbidden(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), 'system_status') => Http::response(['code' => 'forbidden'], 403),
                str_contains($request->url(), '/products') => Http::response([$this->remoteProduct()], 200, ['X-WP-TotalPages' => 1]),
                default => Http::response(['namespaces' => ['wc/v3', 'pll/v1', 'yoast/v1']]),
            };
        });

        $store = $this->connectedStore($this->user, ['capabilities' => null]);

        $this->assertNull(app(WooCommerceConnectionService::class)->test($store));

        $capabilities = $store->fresh()->capabilities;

        $this->assertSame('polylang', $capabilities['language']['provider']);
        $this->assertSame('yoast', $capabilities['seo']['provider']);
    }

    public function test_the_public_index_request_never_sends_credentials(): void
    {
        $this->fakeLiveStore([$this->remoteProduct()]);

        app(WooCommerceConnectionService::class)->test($this->connectedStore($this->user));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/wp-json/') && ! $request->hasHeader('Authorization'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function seoPlugins(): array
    {
        return [
            'yoast' => ['wordpress-seo/wp-seo.php', 'yoast'],
            'rank math' => ['seo-by-rank-math/rank-math.php', 'rank_math'],
        ];
    }

    #[DataProvider('seoPlugins')]
    public function test_seo_sync_is_reported_as_unsupported(string $plugin, string $provider): void
    {
        Http::fake(function (Request $request) use ($plugin) {
            return match (true) {
                str_contains($request->url(), 'system_status') => Http::response(['active_plugins' => [['plugin' => $plugin]]]),
                str_contains($request->url(), '/products') => Http::response([$this->remoteProduct()], 200, ['X-WP-TotalPages' => 1]),
                default => Http::response(['namespaces' => []]),
            };
        });

        $store = $this->connectedStore($this->user, ['capabilities' => null]);

        app(WooCommerceConnectionService::class)->test($store);

        $this->assertSame($provider, $store->fresh()->capabilities['seo']['provider']);
        $this->assertFalse($store->fresh()->capabilities['seo']['writable']);
        $this->get(route('integrations.index'))->assertSee('SEO Sync Not Supported');
    }
}
