<?php

namespace Tests\Feature\Catalog;

use App\Enums\BulkItemStatus;
use App\Enums\CatalogTransferStatus;
use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Jobs\GenerateCatalogExport;
use App\Models\AiGeneration;
use App\Models\BulkGenerationBatch;
use App\Models\BulkGenerationItem;
use App\Models\CatalogTransfer;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Services\Catalog\CellSanitizer;
use App\Services\Catalog\ColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSpreadsheets;
use Tests\TestCase;
use ZipArchive;

class CatalogExportTest extends TestCase
{
    use BuildsSpreadsheets, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function export(array $data = []): CatalogTransfer
    {
        $this->post(route('catalog.exports.store'), [
            'format' => 'csv',
            'scope' => 'approved',
            'languages' => ['en', 'sq', 'de'],
            'all_matching' => 1,
            ...$data,
        ])->assertRedirect(route('catalog.history'));

        return CatalogTransfer::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array{list<string>, list<array<string, mixed>>}
     */
    private function exportedRows(CatalogTransfer $transfer): array
    {
        $path = Storage::disk('local')->path($transfer->stored_path);
        $rows = $transfer->file_type === 'xlsx' ? $this->readXlsx($path) : $this->readCsv($path);
        $headers = array_map(fn (string $header) => str_replace(' ', '_', mb_strtolower($header)), array_shift($rows));

        return [$headers, array_map(fn (array $row) => array_combine($headers, array_pad($row, count($headers), null)), $rows)];
    }

    private function draft(Product $product, ContentLanguage $language, array $content): BulkGenerationItem
    {
        $batch = $this->user->bulkGenerationBatches()->create([
            'submission_token' => fake()->uuid(),
            'status' => BulkGenerationBatch::COMPLETED,
            'preferences' => [],
        ]);

        return $batch->items()->create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'ai_generation_id' => AiGeneration::factory()->for($this->user)->for($product)->create(['generated_content' => $content])->id,
            'language' => $language,
            'status' => BulkItemStatus::Generated,
            'review_status' => BulkGenerationItem::REVIEW_PENDING,
        ]);
    }

    public function test_product_information_only_has_the_documented_columns_and_values(): void
    {
        $product = Product::factory()->for($this->user)->create(['original_name' => 'Tote', 'sku' => 'S-1', 'category' => 'Bags', 'material' => 'Leather', 'details' => 'Nice']);
        $product->externalReferences()->create(['user_id' => $this->user->id, 'source' => 'old-shop', 'external_id' => 'E-9']);

        [$headers, $rows] = $this->exportedRows($this->export(['scope' => 'product_only']));

        $this->assertSame(['local_product_id', 'external_source', 'external_id', 'product_name', 'sku', 'category', 'material', 'details', 'status'], $headers);
        $this->assertSame([
            'local_product_id' => (string) $product->id,
            'external_source' => 'old-shop',
            'external_id' => 'E-9',
            'product_name' => 'Tote',
            'sku' => 'S-1',
            'category' => 'Bags',
            'material' => 'Leather',
            'details' => 'Nice',
            'status' => 'draft',
        ], $rows[0]);
    }

    public function test_content_is_exported_one_row_per_language(): void
    {
        $product = Product::factory()->for($this->user)->create();
        ProductContent::factory()->for($product)->create(['title' => 'English', 'keywords' => ['a', 'b']]);
        ProductContent::factory()->for($product)->language(ContentLanguage::German)->create(['title' => 'Deutsch']);

        [, $rows] = $this->exportedRows($this->export());

        $this->assertCount(2, $rows);
        $this->assertSame(['en', 'English', 'a, b', 'approved'], [$rows[0]['language'], $rows[0]['title'], $rows[0]['keywords'], $rows[0]['content_status']]);
        $this->assertSame('Deutsch', $rows[1]['title']);
    }

    public function test_only_selected_languages_are_exported(): void
    {
        $product = Product::factory()->for($this->user)->create();
        ProductContent::factory()->for($product)->create();
        ProductContent::factory()->for($product)->language(ContentLanguage::German)->create();

        [, $rows] = $this->exportedRows($this->export(['languages' => ['de']]));

        $this->assertSame(['de'], array_column($rows, 'language'));
    }

    public function test_approved_scope_leaves_out_pending_drafts(): void
    {
        $product = Product::factory()->for($this->user)->create();
        ProductContent::factory()->for($product)->create(['title' => 'Saved']);
        $this->draft($product, ContentLanguage::German, ['title' => 'Draft', 'keywords' => ['x']]);

        [, $rows] = $this->exportedRows($this->export());

        $this->assertSame(['Saved'], array_column($rows, 'title'));
    }

    public function test_all_scope_includes_and_labels_pending_drafts(): void
    {
        $product = Product::factory()->for($this->user)->create();
        ProductContent::factory()->for($product)->create(['title' => 'Saved']);
        $this->draft($product, ContentLanguage::German, ['title' => 'Draft', 'keywords' => ['x']]);

        [, $rows] = $this->exportedRows($this->export(['scope' => 'all']));

        $this->assertSame(['approved', 'pending_review'], array_column($rows, 'content_status'));
    }

    public function test_generated_scope_only_includes_ai_content(): void
    {
        $product = Product::factory()->for($this->user)->create();
        ProductContent::factory()->for($product)->create(['title' => 'Manual']);
        ProductContent::factory()->for($product)->language(ContentLanguage::German)->ai()->create(['title' => 'AI']);

        [, $rows] = $this->exportedRows($this->export(['scope' => 'generated']));

        $this->assertSame(['AI'], array_column($rows, 'title'));
        $this->assertSame(ContentSource::Ai->value, $rows[0]['content_source']);
    }

    public function test_a_product_without_content_in_scope_still_appears(): void
    {
        Product::factory()->for($this->user)->create(['original_name' => 'Empty']);

        [, $rows] = $this->exportedRows($this->export());

        $this->assertSame('Empty', $rows[0]['product_name']);
        $this->assertSame('', $rows[0]['title']);
    }

    public function test_selected_and_filtered_products_are_respected(): void
    {
        $chosen = Product::factory()->for($this->user)->create(['original_name' => 'Chosen']);
        Product::factory()->for($this->user)->published()->create(['original_name' => 'Live']);

        [, $selected] = $this->exportedRows($this->export(['all_matching' => null, 'product_ids' => [$chosen->id], 'scope' => 'product_only']));
        [, $filtered] = $this->exportedRows($this->export(['status' => 'published', 'scope' => 'product_only']));

        $this->assertSame(['Chosen'], array_column($selected, 'product_name'));
        $this->assertSame(['Live'], array_column($filtered, 'product_name'));
    }

    public function test_other_users_products_are_never_exported(): void
    {
        $mine = Product::factory()->for($this->user)->create(['original_name' => 'Mine']);
        $foreign = Product::factory()->create(['original_name' => 'Foreign']);

        [, $rows] = $this->exportedRows($this->export(['all_matching' => null, 'product_ids' => [$mine->id, $foreign->id], 'scope' => 'product_only']));

        $this->assertSame(['Mine'], array_column($rows, 'product_name'));
    }

    public function test_an_export_of_nothing_is_refused(): void
    {
        $this->post(route('catalog.exports.store'), ['format' => 'csv', 'scope' => 'product_only', 'product_ids' => [999999]])
            ->assertSessionHasErrors('format');

        $this->assertDatabaseCount('catalog_transfers', 0);
    }

    public function test_csv_is_utf8_with_a_bom_and_keeps_special_characters(): void
    {
        Product::factory()->for($this->user)->create(['original_name' => 'Çantë Große Maß']);

        $transfer = $this->export(['scope' => 'product_only']);
        $raw = Storage::disk('local')->get($transfer->stored_path);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $raw);
        $this->assertStringContainsString('Çantë Große Maß', $raw);
    }

    public function test_xlsx_keeps_special_characters_and_values(): void
    {
        $product = Product::factory()->for($this->user)->create(['original_name' => 'Çantë Große']);
        ProductContent::factory()->for($product)->language(ContentLanguage::Albanian)->create(['title' => 'Lëkurë']);

        [, $rows] = $this->exportedRows($this->export(['format' => 'xlsx']));

        $this->assertSame('Çantë Große', $rows[0]['product_name']);
        $this->assertSame('Lëkurë', $rows[0]['title']);
        $this->assertSame('sq', $rows[0]['language']);
    }

    private function xlsxPart(CatalogTransfer $transfer, string $part): string
    {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($transfer->stored_path));
        $content = $zip->getFromName($part);
        $zip->close();

        return (string) $content;
    }

    public function test_xlsx_has_clear_labels_and_a_styled_header(): void
    {
        Product::factory()->for($this->user)->create();

        $transfer = $this->export(['format' => 'xlsx', 'scope' => 'all']);
        $path = Storage::disk('local')->path($transfer->stored_path);

        $this->assertSame([
            'Local Product ID', 'External Source', 'External ID', 'Product Name', 'SKU', 'Category', 'Material', 'Details', 'Status',
            'Language', 'Title', 'Short Description', 'Long Description', 'Meta Title', 'Meta Description', 'Slug', 'Keywords', 'Content Source', 'Content Status',
        ], $this->readXlsx($path)[0]);

        $styles = $this->xlsxPart($transfer, 'xl/styles.xml');

        $this->assertStringContainsString('<b/>', $styles);
        $this->assertStringContainsString('4F46E5', $styles);
    }

    public function test_xlsx_freezes_the_header_and_filters_every_row(): void
    {
        Product::factory()->for($this->user)->count(2)->create();

        $sheet = $this->xlsxPart($this->export(['format' => 'xlsx', 'scope' => 'product_only']), 'xl/worksheets/sheet1.xml');

        $this->assertMatchesRegularExpression('/<pane[^>]*ySplit="1"[^>]*state="frozen"/', $sheet);
        $this->assertStringContainsString('<autoFilter ref="A1:I3"/>', $sheet);
    }

    public function test_xlsx_sets_column_widths(): void
    {
        Product::factory()->for($this->user)->create();

        $sheet = $this->xlsxPart($this->export(['format' => 'xlsx']), 'xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('<col min="4" max="4" width="32" customWidth="true"', $sheet);
        $this->assertStringContainsString('<col min="13" max="13" width="60" customWidth="true"', $sheet);
    }

    public function test_xlsx_style_count_does_not_grow_with_the_number_of_rows(): void
    {
        Product::factory()->for($this->user)->count(30)->create(['details' => "Two\nlines"]);

        $styles = $this->xlsxPart($this->export(['format' => 'xlsx', 'scope' => 'product_only']), 'xl/styles.xml');

        preg_match('/<cellXfs count="(\d+)"/', $styles, $formats);
        preg_match('/<fonts count="(\d+)"/', $styles, $fonts);

        $this->assertLessThanOrEqual(4, (int) $formats[1]);
        $this->assertLessThanOrEqual(4, (int) $fonts[1]);
    }

    public function test_long_descriptions_cannot_stretch_rows_and_stay_complete(): void
    {
        $long = trim(str_repeat("Çantë lëkure, Große Tasche.\n\n", 600));
        $product = Product::factory()->for($this->user)->create();
        ProductContent::factory()->for($product)->create(['long_description' => $long]);

        $transfer = $this->export(['format' => 'xlsx']);
        $sheet = $this->xlsxPart($transfer, 'xl/worksheets/sheet1.xml');

        $this->assertSame(2, preg_match_all('/<row [^>]*ht="[\d.]+" customHeight="1"/', $sheet));
        $this->assertMatchesRegularExpression('/<row r="2"[^>]*ht="18"/', $sheet);

        [, $rows] = $this->exportedRows($transfer);

        $this->assertSame($long, $rows[0]['long_description']);
    }

    public function test_xlsx_and_csv_contain_the_same_rows_and_values(): void
    {
        $product = Product::factory()->for($this->user)->published()->create(['original_name' => 'Çantë Große', 'details' => "Line one\nLine two", 'material' => null]);
        $product->externalReferences()->create(['user_id' => $this->user->id, 'source' => 'old-shop', 'external_id' => 'E-1']);
        ProductContent::factory()->for($product)->language(ContentLanguage::Albanian)->create(['title' => 'Lëkurë', 'keywords' => ['çantë', 'lëkurë']]);
        ProductContent::factory()->for($product)->language(ContentLanguage::German)->ai()->create(['title' => 'Maß', 'long_description' => '=SUM(A1)']);
        Product::factory()->for($this->user)->create(['original_name' => 'No content']);

        [$csvHeaders, $csvRows] = $this->exportedRows($this->export(['format' => 'csv', 'scope' => 'all']));
        [$xlsxHeaders, $xlsxRows] = $this->exportedRows($this->export(['format' => 'xlsx', 'scope' => 'all']));

        $asText = fn (array $rows) => array_map(fn (array $row) => array_map(fn ($value) => (string) $value, $row), $rows);

        $this->assertSame($csvHeaders, $xlsxHeaders);
        $this->assertCount(3, $xlsxRows);
        $this->assertSame($asText($csvRows), $asText($xlsxRows));
    }

    public function test_csv_is_not_affected_by_the_xlsx_formatting(): void
    {
        Product::factory()->for($this->user)->create(['original_name' => 'Tote', 'sku' => 'S-1', 'category' => null, 'material' => null, 'details' => null]);

        $raw = Storage::disk('local')->get($this->export(['scope' => 'product_only'])->stored_path);

        $this->assertStringStartsWith("\xEF\xBB\xBFlocal_product_id,external_source,external_id,product_name,sku,category,material,details,status", $raw);
    }

    public function test_xlsx_labels_are_still_recognized_when_imported_again(): void
    {
        $this->assertSame(
            ['external_id', 'original_name', 'sku', 'details', 'language', 'short_description', 'meta_title', null],
            app(ColumnMapper::class)->suggest(['External ID', 'Product Name', 'SKU', 'Details', 'Language', 'Short Description', 'Meta Title', 'Local Product ID']),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formulas(): array
    {
        return [
            'equals' => ['=HYPERLINK("http://evil","x")'],
            'plus' => ['+1+1'],
            'minus' => ['-2+3'],
            'at' => ['@SUM(A1)'],
            'tab' => ["\t=1"],
            'leading space' => [' =1+1'],
        ];
    }

    #[DataProvider('formulas')]
    public function test_formulas_are_neutralized(string $formula): void
    {
        $this->assertSame("'".$formula, CellSanitizer::forExport($formula));
    }

    public function test_normal_values_and_numbers_are_unchanged(): void
    {
        $this->assertSame('Tote', CellSanitizer::forExport('Tote'));
        $this->assertSame(5, CellSanitizer::forExport(5));
    }

    public function test_formula_injection_is_prevented_in_real_files(): void
    {
        Product::factory()->for($this->user)->create(['original_name' => '=cmd|calc', 'details' => '@evil']);

        foreach (['csv', 'xlsx'] as $format) {
            [, $rows] = $this->exportedRows($this->export(['format' => $format, 'scope' => 'product_only']));

            $this->assertSame("'=cmd|calc", $rows[0]['product_name']);
            $this->assertSame("'@evil", $rows[0]['details']);
        }
    }

    public function test_exports_never_contain_user_details_or_api_keys(): void
    {
        Product::factory()->for($this->user)->create();

        $raw = Storage::disk('local')->get($this->export(['scope' => 'product_only'])->stored_path);

        $this->assertStringNotContainsString($this->user->email, $raw);
        $this->assertStringNotContainsString('test-gemini-key', $raw);
    }

    public function test_only_the_owner_can_download_an_export(): void
    {
        Product::factory()->for($this->user)->create();
        $transfer = $this->export(['scope' => 'product_only']);

        $this->get(route('catalog.exports.download', $transfer))->assertOk()->assertDownload($transfer->original_name);

        $this->actingAs(User::factory()->create());

        $this->get(route('catalog.exports.download', $transfer))->assertForbidden();
    }

    public function test_an_expired_export_shows_a_friendly_message(): void
    {
        Product::factory()->for($this->user)->create();
        $transfer = $this->export(['scope' => 'product_only']);
        $transfer->update(['expires_at' => now()->subDay()]);

        $this->artisan('catalog:prune')->assertSuccessful();

        $this->get(route('catalog.exports.download', $transfer))->assertSessionHasErrors('download');
    }

    public function test_history_records_the_export(): void
    {
        Product::factory()->for($this->user)->count(2)->create();

        $transfer = $this->export(['format' => 'xlsx', 'scope' => 'product_only']);

        $this->assertSame(CatalogTransferStatus::Completed, $transfer->status);
        $this->assertSame('xlsx', $transfer->file_type);
        $this->assertSame(2, $transfer->total_records);
        $this->assertSame(2, $transfer->successful_records);
        $this->assertNotNull($transfer->completed_at);

        $this->get(route('catalog.history'))->assertSee(['Export', 'XLSX', 'Download']);
    }

    public function test_large_exports_go_to_the_queue(): void
    {
        config(['catalog.export_sync_limit' => 1]);
        Queue::fake();
        Product::factory()->for($this->user)->count(2)->create();

        $transfer = $this->export(['scope' => 'product_only']);

        Queue::assertPushed(GenerateCatalogExport::class, fn ($job) => $job->transferId === $transfer->id);
        $this->assertSame(CatalogTransferStatus::Queued, $transfer->status);
    }

    public function test_export_options_are_validated(): void
    {
        $this->post(route('catalog.exports.store'), ['format' => 'pdf', 'scope' => 'approved'])
            ->assertSessionHasErrors(['format', 'languages']);
    }
}
