<?php

namespace Tests\Feature\Api;

use App\Enums\CatalogTransferStatus;
use App\Enums\ContentSource;
use App\Jobs\GenerateCatalogExport;
use App\Models\CatalogTransfer;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsesApiTokens;
use Tests\TestCase;

class ExportApiTest extends TestCase
{
    use RefreshDatabase, UsesApiTokens;

    private User $user;

    private string $token;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->token = $this->tokenFor($this->user);
        $this->product = Product::factory()->for($this->user)->create(['original_name' => 'Exported Product']);
        ProductContent::factory()->for($this->product)->ai()->create(['language' => 'en', 'title' => 'Approved Title']);
        ProductContent::factory()->for($this->product)->create(['language' => 'de', 'title' => 'Imported Title', 'source' => ContentSource::WooCommerce]);
    }

    private function client(): static
    {
        return $this->api($this->token);
    }

    private function create(array $body = ['format' => 'csv'])
    {
        return $this->client()->postJson('/api/v1/exports', $body);
    }

    private function downloadBody(int $id): string
    {
        return $this->client()->get("/api/v1/exports/{$id}/download")->assertOk()->streamedContent();
    }

    public function test_an_export_is_queued_and_returns_an_identifier(): void
    {
        Queue::fake();

        $response = $this->create();
        $transfer = CatalogTransfer::query()->sole();

        $response->assertAccepted()
            ->assertHeader('Location', route('api.v1.exports.show', $transfer))
            ->assertExactJson(['data' => ['export_id' => $transfer->id, 'status' => 'queued', 'format' => 'csv']]);

        Queue::assertPushed(GenerateCatalogExport::class, fn ($job) => $job->transferId === $transfer->id);

        $this->client()->getJson("/api/v1/exports/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.download_url', null);

        $this->client()->getJson("/api/v1/exports/{$transfer->id}/download")->assertConflict()->assertJsonPath('code', 'export_not_ready');
    }

    public function test_a_finished_export_can_be_downloaded_without_exposing_its_path(): void
    {
        $id = $this->create()->json('data.export_id');

        $status = $this->client()->getJson("/api/v1/exports/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.download_url', route('api.v1.exports.download', $id));

        $this->assertStringNotContainsString('catalog/exports', str_replace('\\/', '/', $status->getContent()));
        $this->assertStringNotContainsString('stored_path', $status->getContent());
        $this->assertStringContainsString('Exported Product', $this->downloadBody($id));
    }

    public function test_approved_only_is_the_default_and_leaves_out_unapproved_text(): void
    {
        $body = $this->downloadBody($this->create()->json('data.export_id'));

        $this->assertStringContainsString('Approved Title', $body);
        $this->assertStringNotContainsString('Imported Title', $body);
    }

    public function test_all_saved_content_can_be_requested_but_never_drafts(): void
    {
        $body = $this->downloadBody($this->create(['format' => 'csv', 'approved_only' => false])->json('data.export_id'));

        $this->assertStringContainsString('Imported Title', $body);
    }

    public function test_language_and_product_selection_are_applied(): void
    {
        $other = Product::factory()->for($this->user)->create(['original_name' => 'Left Out']);
        ProductContent::factory()->for($this->product)->ai()->create(['language' => 'sq', 'title' => 'Titull Shqip']);

        $body = $this->downloadBody($this->create(['format' => 'csv', 'languages' => ['sq'], 'product_ids' => [$this->product->id]])->json('data.export_id'));

        $this->assertStringContainsString('Titull Shqip', $body);
        $this->assertStringNotContainsString('Approved Title', $body);
        $this->assertStringNotContainsString('Left Out', $body);
        $this->assertNotNull($other->fresh());
    }

    public function test_xlsx_is_supported(): void
    {
        $id = $this->create(['format' => 'xlsx'])->json('data.export_id');

        $response = $this->client()->get("/api/v1/exports/{$id}/download")->assertOk();

        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    public function test_input_is_validated(): void
    {
        $this->create(['format' => 'pdf'])->assertUnprocessable()->assertJsonValidationErrors('format');
        $this->create(['format' => 'csv', 'languages' => ['xx']])->assertUnprocessable();
        $this->create(['format' => 'csv', 'product_ids' => ['abc']])->assertUnprocessable();
    }

    public function test_another_users_products_cant_be_exported(): void
    {
        $theirs = Product::factory()->for(User::factory()->create())->create(['original_name' => 'Secret']);

        $this->create(['format' => 'csv', 'product_ids' => [$theirs->id]])->assertUnprocessable()->assertJsonPath('code', 'no_products');

        $body = $this->downloadBody($this->create(['format' => 'csv', 'product_ids' => [$theirs->id, $this->product->id]])->json('data.export_id'));

        $this->assertStringNotContainsString('Secret', $body);
    }

    public function test_another_users_export_is_not_found(): void
    {
        $id = $this->create()->json('data.export_id');
        $other = $this->tokenFor(User::factory()->create());

        $this->api($other)->getJson("/api/v1/exports/{$id}")->assertNotFound();
        $this->api($other)->getJson("/api/v1/exports/{$id}/download")->assertNotFound();
    }

    public function test_an_import_record_isnt_reachable_as_an_export(): void
    {
        $import = $this->user->catalogTransfers()->create(['operation' => CatalogTransfer::IMPORT, 'file_type' => 'csv', 'status' => CatalogTransferStatus::Completed, 'stored_path' => 'catalog/imports/x.csv', 'options' => [], 'summary' => []]);

        $this->client()->getJson("/api/v1/exports/{$import->id}")->assertNotFound();
        $this->client()->getJson("/api/v1/exports/{$import->id}/download")->assertNotFound();
    }

    public function test_an_expired_export_cant_be_downloaded(): void
    {
        $id = $this->create()->json('data.export_id');

        $this->travel((int) config('catalog.retention_days') + 1)->days();

        $this->client()->getJson("/api/v1/exports/{$id}")->assertJsonPath('data.download_url', null);
        $this->client()->getJson("/api/v1/exports/{$id}/download")->assertStatus(410)->assertJsonPath('code', 'export_expired');
    }

    public function test_a_failed_export_is_reported(): void
    {
        Queue::fake();
        $id = $this->create()->json('data.export_id');

        (new GenerateCatalogExport($id))->failed(new \RuntimeException('disk path C:\\secret'));

        $this->client()->getJson("/api/v1/exports/{$id}")->assertJsonPath('data.status', 'failed')->assertDontSee('secret');
        $this->client()->getJson("/api/v1/exports/{$id}/download")->assertConflict()->assertJsonPath('code', 'export_failed');
    }

    public function test_export_creation_is_idempotent(): void
    {
        Queue::fake();
        $headers = ['Idempotency-Key' => 'export-1'];

        $first = $this->client()->withHeaders($headers)->postJson('/api/v1/exports', ['format' => 'csv'])->json('data.export_id');
        $second = $this->client()->withHeaders($headers)->postJson('/api/v1/exports', ['format' => 'csv'])->assertHeader('Idempotency-Replayed', 'true')->json('data.export_id');

        $this->assertSame($first, $second);
        Queue::assertPushed(GenerateCatalogExport::class, 1);
    }

    public function test_export_creation_is_rate_limited(): void
    {
        Queue::fake();
        config(['api.rate_limits.exports' => 1]);

        $this->create()->assertAccepted();
        $this->create()->assertTooManyRequests()->assertJsonPath('code', 'rate_limited');
    }
}
