<?php

namespace Tests\Feature\Catalog;

use App\Enums\CatalogTransferStatus;
use App\Enums\ContentLanguage;
use App\Enums\ImportStrategy;
use App\Enums\ProductStatus;
use App\Jobs\ProcessCatalogImport;
use App\Models\CatalogImportRow;
use App\Models\CatalogTransfer;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductExternalReference;
use App\Models\User;
use App\Services\Catalog\CatalogImporter;
use App\Services\Catalog\ColumnMapper;
use App\Services\ProductService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSpreadsheets;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use BuildsSpreadsheets, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function upload(UploadedFile $file): CatalogTransfer
    {
        $this->post(route('catalog.imports.store'), ['file' => $file])->assertRedirect();

        return CatalogTransfer::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<int, ?string>|null  $mapping
     */
    private function map(CatalogTransfer $transfer, ?array $mapping = null, array $extra = []): CatalogTransfer
    {
        $this->post(route('catalog.imports.mapping.save', $transfer), [
            'mapping' => $mapping ?? $transfer->options['suggested'],
            'default_language' => 'en',
            'source' => 'old-shop',
            ...$extra,
        ])->assertSessionHasNoErrors();

        return $transfer->refresh();
    }

    private function confirm(CatalogTransfer $transfer, array $data = ['strategy' => 'skip']): void
    {
        $this->post(route('catalog.imports.confirm', $transfer), $data)->assertRedirect(route('catalog.imports.show', $transfer));
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function import(array $rows, array $confirm = ['strategy' => 'skip']): CatalogTransfer
    {
        $transfer = $this->map($this->upload($this->csvFile($rows)));
        $this->confirm($transfer, $confirm);

        return $transfer->refresh();
    }

    public function test_the_products_page_offers_import_export_and_selection(): void
    {
        Product::factory()->for($this->user)->create();

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee(['Import Products', 'Export Products', 'Generate Content with AI'])
            ->assertSee('data-product-checkbox', false);
    }

    public function test_a_csv_is_uploaded_with_suggested_mappings_and_stays_private(): void
    {
        $transfer = $this->upload($this->csvFile([
            ['product_name', 'product_category', 'SKU', 'Weird Column'],
            ['Tote', 'Bags', 'T-1', 'x'],
        ]));

        $this->assertSame(['original_name', 'category', 'sku', null], $transfer->options['suggested']);
        Storage::disk('local')->assertExists($transfer->stored_path);
        $this->assertStringStartsWith("catalog/imports/{$this->user->id}/", $transfer->stored_path);

        $this->get(route('catalog.imports.mapping', $transfer))->assertOk()->assertSee(['product_name', 'Weird Column', 'Tote']);
    }

    public function test_an_xlsx_file_imports(): void
    {
        $transfer = $this->map($this->upload($this->xlsxFile([
            ['Name', 'SKU', 'Category'],
            ['Excel Tote', 'X-1', 'Bags'],
            ['Excel Mug', 12345, 'Kitchen'],
        ])));

        $this->assertSame(2, $transfer->summary['valid_rows']);

        $this->confirm($transfer);

        $this->assertDatabaseHas('products', ['user_id' => $this->user->id, 'original_name' => 'Excel Tote', 'sku' => 'X-1']);
        $this->assertDatabaseHas('products', ['original_name' => 'Excel Mug', 'sku' => '12345']);
    }

    public function test_semicolon_csvs_and_windows_encoding_are_read(): void
    {
        $content = mb_convert_encoding("Name;Category\nÇantë lëkure;Große Taschen\n", 'Windows-1252', 'UTF-8');

        $transfer = $this->map($this->upload(UploadedFile::fake()->createWithContent('legacy.csv', $content)));
        $this->confirm($transfer);

        $this->assertDatabaseHas('products', ['original_name' => 'Çantë lëkure', 'category' => 'Große Taschen']);
    }

    public function test_unsupported_and_malformed_files_are_rejected(): void
    {
        $this->post(route('catalog.imports.store'), ['file' => UploadedFile::fake()->createWithContent('a.pdf', '%PDF')])
            ->assertSessionHasErrors('file');

        $this->post(route('catalog.imports.store'), ['file' => UploadedFile::fake()->createWithContent('fake.xlsx', 'not a zip')])
            ->assertSessionHasErrors('file');

        $this->post(route('catalog.imports.store'), ['file' => UploadedFile::fake()->createWithContent('binary.csv', "a\0b\0c")])
            ->assertSessionHasErrors('file');

        $this->post(route('catalog.imports.store'), ['file' => UploadedFile::fake()->createWithContent('empty.csv', "name,sku\n")])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('catalog_transfers', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('catalog/imports'));
    }

    public function test_oversized_files_are_rejected(): void
    {
        config(['catalog.max_file_kb' => 1]);

        $this->post(route('catalog.imports.store'), ['file' => UploadedFile::fake()->create('big.csv', 5, 'text/csv')])
            ->assertSessionHasErrors('file');
    }

    public function test_too_many_rows_are_rejected_with_a_clear_message(): void
    {
        config(['catalog.max_rows' => 2]);
        $transfer = $this->upload($this->csvFile([['Name'], ['A'], ['B'], ['C']]));

        $this->post(route('catalog.imports.mapping.save', $transfer), [
            'mapping' => ['original_name'],
            'default_language' => 'en',
        ])->assertSessionHasErrors('mapping');
    }

    public function test_the_product_name_mapping_is_required(): void
    {
        $transfer = $this->upload($this->csvFile([['Thing', 'SKU'], ['A', '1']]));

        $this->post(route('catalog.imports.mapping.save', $transfer), [
            'mapping' => [null, 'sku'],
            'default_language' => 'en',
        ])->assertSessionHasErrors('mapping');

        $this->assertDatabaseCount('catalog_import_rows', 0);
    }

    public function test_one_destination_cannot_be_mapped_twice(): void
    {
        $transfer = $this->upload($this->csvFile([['A', 'B'], ['1', '2']]));

        $this->post(route('catalog.imports.mapping.save', $transfer), [
            'mapping' => ['original_name', 'original_name'],
            'default_language' => 'en',
        ])->assertSessionHasErrors('mapping');
    }

    public function test_mappings_can_be_chosen_manually_and_unknown_columns_are_ignored(): void
    {
        $transfer = $this->map($this->upload($this->csvFile([['Foo', 'Bar', 'Baz'], ['Manual Name', 'Cat', 'ignored']])), ['original_name', 'category', null]);
        $this->confirm($transfer);

        $this->assertDatabaseHas('products', ['original_name' => 'Manual Name', 'category' => 'Cat']);
    }

    public function test_platform_names_are_reserved_for_future_integrations(): void
    {
        $transfer = $this->upload($this->csvFile([['Name'], ['A']]));

        $this->post(route('catalog.imports.mapping.save', $transfer), [
            'mapping' => ['original_name'],
            'default_language' => 'en',
            'source' => 'shopify',
        ])->assertSessionHasErrors('source');
    }

    public function test_the_preview_writes_no_products_and_reports_counts(): void
    {
        Product::factory()->for($this->user)->create(['sku' => 'EXISTS']);

        $transfer = $this->map($this->upload($this->csvFile([
            ['Name', 'SKU', 'Status'],
            ['Good', 'G-1', 'draft'],
            ['', 'G-2', 'draft'],
            ['Bad Status', 'G-3', 'archived'],
            ['Existing', 'EXISTS', 'published'],
        ])));

        $this->assertDatabaseCount('products', 1);
        $this->assertSame([
            'total_rows' => 4,
            'valid_rows' => 2,
            'invalid_rows' => 2,
            'products' => 2,
            'possible_duplicates' => 1,
        ], $transfer->summary);

        $this->get(route('catalog.imports.preview', $transfer))
            ->assertOk()
            ->assertSee(['Row 3', 'The Product Name field is required', 'Status must be draft or published', 'Skip existing products', 'Matches by sku']);
    }

    public function test_validation_errors_can_be_downloaded_safely(): void
    {
        $transfer = $this->map($this->upload($this->csvFile([['Name', 'Status'], ['=HYPERLINK("x")', 'bad']])));

        $content = $this->get(route('catalog.imports.errors', $transfer))->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Status must be draft or published', $content);
        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    public function test_oversized_values_and_unsupported_languages_get_row_errors(): void
    {
        $transfer = $this->map($this->upload($this->csvFile([
            ['Name', 'Category', 'Language', 'Title'],
            ['Long', str_repeat('x', 121), 'en', 'T'],
            ['French', 'Bags', 'fr', 'Titre'],
        ])));

        $errors = $transfer->rows()->where('status', CatalogImportRow::INVALID)->orderBy('first_row')->pluck('errors');

        $this->assertStringContainsString('Category', $errors[0][0]);
        $this->assertStringContainsString('Unsupported language', $errors[1][0]);
    }

    public function test_duplicates_within_a_file_are_rejected(): void
    {
        $transfer = $this->map($this->upload($this->csvFile([
            ['External ID', 'Name', 'SKU'],
            ['A', 'First', 'S-1'],
            ['A', 'First again', 'S-9'],
            ['B', 'Second', 'S-1'],
            ['', 'Same Name', ''],
            ['', 'same name', ''],
        ])));

        $this->assertSame(3, $transfer->summary['invalid_rows']);

        $messages = $transfer->rows()->where('status', CatalogImportRow::INVALID)->orderBy('first_row')->pluck('errors')->flatten()->implode(' ');

        $this->assertStringContainsString('Duplicate of the product on row 2', $messages);
        $this->assertStringContainsString('Duplicate SKU', $messages);
        $this->assertStringContainsString('Duplicate of the product on row 5', $messages);
    }

    public function test_translations_become_one_product_with_several_languages(): void
    {
        $this->import([
            ['External ID', 'Name', 'Category', 'Language', 'Title', 'Keywords', 'Slug'],
            ['E-1', 'Tote', 'Bags', 'en', 'Leather Tote', 'tote; bag', ''],
            ['E-1', 'Tote', 'Bags', 'al', 'Çantë lëkure', 'çantë', ''],
            ['E-1', 'Tote', 'Bags', 'Deutsch', 'Große Tasche', 'tasche', 'Große Tasche!'],
        ]);

        $product = Product::sole();

        $transfer = CatalogTransfer::sole();

        $this->assertSame(1, $transfer->summary['created']);
        $this->assertSame(3, $transfer->successful_records);
        $this->assertCount(3, $product->contents);
        $this->assertSame('Çantë lëkure', $product->contentFor(ContentLanguage::Albanian)->title);
        $this->assertSame('grosse-tasche', $product->contentFor(ContentLanguage::German)->slug);
        $this->assertSame(['tote', 'bag'], $product->contentFor(ContentLanguage::English)->keywords);
    }

    public function test_the_same_language_twice_for_one_product_is_rejected(): void
    {
        $transfer = $this->map($this->upload($this->csvFile([
            ['SKU', 'Name', 'Language', 'Title'],
            ['S', 'Tote', 'en', 'One'],
            ['S', 'Tote', 'en', 'Two'],
        ])));

        $this->assertSame(1, $transfer->summary['invalid_rows']);
    }

    public function test_external_ids_are_stored_separately_from_local_ids(): void
    {
        $this->import([['External ID', 'Name'], ['999', 'Tote']]);

        $reference = ProductExternalReference::sole();

        $this->assertSame('old-shop', $reference->source);
        $this->assertSame('999', $reference->external_id);
        $this->assertNotSame(999, Product::sole()->id);
    }

    public function test_new_products_default_to_draft_and_belong_to_the_importer(): void
    {
        $this->import([['Name', 'Status'], ['Tote', ''], ['Mug', 'Published']]);

        $this->assertSame(ProductStatus::Draft, Product::where('original_name', 'Tote')->sole()->status);
        $this->assertSame(ProductStatus::Published, Product::where('original_name', 'Mug')->sole()->status);
        $this->assertSame([$this->user->id], Product::pluck('user_id')->unique()->all());
    }

    public function test_existing_products_are_skipped_by_default(): void
    {
        $existing = Product::factory()->for($this->user)->create(['sku' => 'S-1', 'original_name' => 'Original']);
        ProductContent::factory()->for($existing)->create(['title' => 'Handwritten']);

        $transfer = $this->import([['SKU', 'Name', 'Title'], ['S-1', 'Changed', 'Replaced']]);

        $this->assertSame('Original', $existing->fresh()->original_name);
        $this->assertSame('Handwritten', ProductContent::sole()->title);
        $this->assertSame(1, $transfer->summary['skipped']);
    }

    public function test_updates_require_confirmation_and_chosen_fields(): void
    {
        $transfer = $this->map($this->upload($this->csvFile([['SKU', 'Name'], ['S-1', 'Changed']])));

        $this->post(route('catalog.imports.confirm', $transfer), ['strategy' => 'update'])
            ->assertSessionHasErrors(['update_fields', 'confirm_update']);

        $this->assertSame(CatalogTransferStatus::Validated, $transfer->fresh()->status);
    }

    public function test_only_selected_fields_are_updated_and_blanks_never_erase(): void
    {
        $existing = Product::factory()->for($this->user)->create(['sku' => 'S-1', 'original_name' => 'Original', 'category' => 'Keep', 'material' => 'Old']);

        $this->import(
            [['SKU', 'Name', 'Category', 'Material'], ['S-1', 'New Name', '', 'New Material']],
            ['strategy' => 'update', 'update_fields' => ['category', 'material'], 'confirm_update' => '1'],
        );

        $existing->refresh();

        $this->assertSame('Original', $existing->original_name);
        $this->assertSame('Keep', $existing->category);
        $this->assertSame('New Material', $existing->material);
    }

    public function test_translation_updates_respect_the_chosen_options(): void
    {
        $existing = Product::factory()->for($this->user)->create(['sku' => 'S-1']);
        ProductContent::factory()->for($existing)->create(['title' => 'Handwritten']);

        $this->import(
            [['SKU', 'Name', 'Language', 'Title'], ['S-1', 'N', 'en', 'Replaced'], ['S-1', 'N', 'de', 'Neu']],
            ['strategy' => 'update', 'update_fields' => ['contents_missing'], 'confirm_update' => '1'],
        );

        $this->assertSame('Handwritten', $existing->contentFor(ContentLanguage::English)->title);
        $this->assertSame('Neu', $existing->fresh()->contentFor(ContentLanguage::German)->title);
    }

    public function test_name_only_matches_are_never_updated(): void
    {
        $existing = Product::factory()->for($this->user)->create(['sku' => null, 'original_name' => 'Tote', 'category' => 'Keep']);

        $transfer = $this->import(
            [['Name', 'Category'], ['Tote', 'Changed']],
            ['strategy' => 'update', 'update_fields' => ['category'], 'confirm_update' => '1'],
        );

        $this->assertSame('Keep', $existing->fresh()->category);
        $this->assertSame(1, $transfer->summary['skipped']);
    }

    public function test_import_as_new_needs_approval_and_only_applies_to_name_matches(): void
    {
        Product::factory()->for($this->user)->create(['sku' => null, 'original_name' => 'Tote']);
        Product::factory()->for($this->user)->create(['sku' => 'S-1', 'original_name' => 'Mug']);

        $transfer = $this->map($this->upload($this->csvFile([['Name', 'SKU'], ['Tote', ''], ['Other', 'S-1']])));

        $this->post(route('catalog.imports.confirm', $transfer), ['strategy' => 'create_new'])->assertSessionHasErrors('approve_new');

        $this->confirm($transfer, ['strategy' => 'create_new', 'approve_new' => '1']);

        $this->assertSame(2, Product::where('original_name', 'Tote')->count());
        $this->assertSame(1, Product::where('sku', 'S-1')->count());
        $this->assertSame(1, $transfer->fresh()->summary['skipped']);
    }

    public function test_another_users_products_are_never_matched_or_changed(): void
    {
        $foreign = Product::factory()->create(['sku' => 'SHARED', 'original_name' => 'Foreign']);

        $this->import(
            [['SKU', 'Name'], ['SHARED', 'Mine']],
            ['strategy' => 'update', 'update_fields' => ['original_name'], 'confirm_update' => '1'],
        );

        $this->assertSame('Foreign', $foreign->fresh()->original_name);
        $this->assertDatabaseHas('products', ['user_id' => $this->user->id, 'sku' => 'SHARED', 'original_name' => 'Mine']);
    }

    public function test_another_users_import_is_inaccessible(): void
    {
        $transfer = $this->upload($this->csvFile([['Name'], ['A']]));
        $this->actingAs(User::factory()->create());

        $this->get(route('catalog.imports.mapping', $transfer))->assertForbidden();
        $this->get(route('catalog.imports.preview', $transfer))->assertForbidden();
        $this->get(route('catalog.imports.errors', $transfer))->assertForbidden();
        $this->post(route('catalog.imports.confirm', $transfer), ['strategy' => 'skip'])->assertForbidden();
    }

    public function test_a_confirmed_import_cannot_run_twice(): void
    {
        $transfer = $this->map($this->upload($this->csvFile([['Name'], ['Tote']])));

        $this->confirm($transfer);
        $this->confirm($transfer);

        $this->assertSame(1, Product::count());
    }

    public function test_the_upload_is_deleted_once_finished_and_history_is_recorded(): void
    {
        $transfer = $this->map($this->upload($this->csvFile([['Name', 'Status'], ['Tote', 'draft'], ['Bad', 'nope']])));
        $path = $transfer->stored_path;

        $this->confirm($transfer);
        $transfer->refresh();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(CatalogTransferStatus::CompletedWithErrors, $transfer->status);
        $this->assertSame(2, $transfer->total_records);
        $this->assertSame(1, $transfer->successful_records);
        $this->assertSame(1, $transfer->failed_records);
        $this->assertNotNull($transfer->completed_at);

        $this->get(route('catalog.imports.show', $transfer))->assertOk()->assertSee(['Products created', 'Status must be draft or published']);
        $this->get(route('catalog.history'))->assertOk()->assertSee('products.csv');
    }

    public function test_an_uploaded_xlsx_is_deleted_once_finished(): void
    {
        $transfer = $this->map($this->upload($this->xlsxFile([['Name'], ['Tote']])));
        $path = $transfer->stored_path;

        $this->confirm($transfer);

        $this->assertFileDoesNotExist(Storage::disk('local')->path($path));
    }

    public function test_large_imports_go_to_the_queue(): void
    {
        config(['catalog.import_sync_limit' => 1]);
        Queue::fake();

        $transfer = $this->map($this->upload($this->csvFile([['Name'], ['A'], ['B']])));
        $this->confirm($transfer);

        Queue::assertPushed(ProcessCatalogImport::class, fn ($job) => $job->transferId === $transfer->id);
        $this->assertDatabaseCount('products', 0);
        $this->assertSame(CatalogTransferStatus::Queued, $transfer->fresh()->status);
        $this->get(route('catalog.imports.show', $transfer))->assertSee('queue worker');
    }

    public function test_large_files_are_processed_in_chunks(): void
    {
        config(['catalog.chunk_size' => 7, 'catalog.import_sync_limit' => 1]);
        Queue::fake();

        $rows = [['Name', 'SKU']];

        foreach (range(1, 40) as $number) {
            $rows[] = ["Product {$number}", "S-{$number}"];
        }

        $transfer = $this->map($this->upload($this->csvFile($rows)));
        $this->confirm($transfer);

        app(CatalogImporter::class)->process($transfer->refresh());

        $this->assertSame(40, Product::count());
        $this->assertSame(40, $transfer->fresh()->successful_records);
    }

    public function test_a_retried_import_creates_no_duplicates(): void
    {
        config(['catalog.import_sync_limit' => 0]);
        Queue::fake();

        $transfer = $this->map($this->upload($this->csvFile([['Name'], ['A'], ['B'], ['C']])));
        $this->confirm($transfer);
        $transfer->refresh();

        // The first row finishes, then the worker dies before the rest.
        $importer = app(CatalogImporter::class);
        $first = $transfer->rows()->orderBy('id')->first();
        app(ProductService::class)->create($this->user, ['original_name' => 'A', 'status' => 'draft']);
        $first->update(['result' => CatalogImportRow::CREATED]);

        $importer->process($transfer);
        $importer->process($transfer->refresh());

        $this->assertSame(1, Product::where('original_name', 'A')->count());
        $this->assertSame(3, Product::count());
    }

    public function test_one_failing_row_does_not_stop_the_others(): void
    {
        $service = $this->partialMock(ProductService::class);
        $service->shouldReceive('create')->withArgs(fn ($user, $attributes) => $attributes['original_name'] === 'Broken')->andThrow(new Exception('secret detail'));

        $transfer = $this->map($this->upload($this->csvFile([['Name'], ['Fine'], ['Broken'], ['Also Fine']])));
        $this->confirm($transfer);
        $transfer->refresh();

        $this->assertSame(CatalogTransferStatus::CompletedWithErrors, $transfer->status);
        $this->assertSame(2, $transfer->successful_records);
        $this->assertSame(1, $transfer->failed_records);

        $this->get(route('catalog.imports.show', $transfer))->assertSee('couldn&#039;t be saved', false)->assertDontSee('secret detail');
    }

    public function test_a_failed_import_can_continue_from_where_it_stopped(): void
    {
        $transfer = $this->map($this->upload($this->csvFile([['Name'], ['A'], ['B']])));

        app(CatalogImporter::class)->confirm($transfer, ImportStrategy::Skip, []);
        app(CatalogImporter::class)->markFailed($transfer, new Exception('boom'));

        $this->assertSame(CatalogTransferStatus::Failed, $transfer->fresh()->status);
        $this->assertSame(0, Product::count());

        $this->post(route('catalog.imports.retry', $transfer))->assertRedirect();

        $this->assertSame(2, Product::count());
        $this->assertSame(CatalogTransferStatus::Completed, $transfer->fresh()->status);
    }

    public function test_a_file_exported_here_imports_back_with_its_text_intact(): void
    {
        $this->import([['Name', 'Details'], ["'=SUM(A1)", "'-5 degrees"]]);

        $product = Product::sole();

        $this->assertSame('=SUM(A1)', $product->original_name);
        $this->assertSame('-5 degrees', $product->details);
    }

    public function test_expired_files_are_pruned_but_history_is_kept(): void
    {
        $transfer = $this->map($this->upload($this->csvFile([['Name'], ['A']])));
        $path = $transfer->stored_path;
        $transfer->update(['expires_at' => now()->subDay()]);

        $this->artisan('catalog:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('catalog_import_rows', 0);
        $this->assertModelExists($transfer);
    }

    public function test_the_mapper_only_suggests_each_destination_once(): void
    {
        $this->assertSame(['original_name', null], app(ColumnMapper::class)->suggest(['Name', 'Product Name']));
    }

    public function test_guests_cannot_reach_catalog_pages(): void
    {
        auth()->logout();

        $this->get(route('catalog.imports.create'))->assertRedirect(route('login'));
        $this->get(route('catalog.history'))->assertRedirect(route('login'));
        $this->get(route('catalog.exports.create'))->assertRedirect(route('login'));
    }
}
