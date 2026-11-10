<?php

namespace App\Services\Catalog;

use App\Enums\CatalogTransferStatus;
use App\Enums\ContentLanguage;
use App\Models\CatalogImportRow;
use App\Models\CatalogTransfer;
use App\Models\ProductExternalReference;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Catalog\Exceptions\CatalogFileException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Reads a whole file against a column mapping and stages the result for review.
 * Nothing is written to products here.
 */
final class ImportAnalyzer
{
    private const LANGUAGE_ALIASES = [
        'en' => 'en', 'eng' => 'en', 'english' => 'en', 'englisch' => 'en', 'anglisht' => 'en',
        'sq' => 'sq', 'al' => 'sq', 'alb' => 'sq', 'albanian' => 'sq', 'shqip' => 'sq', 'albanisch' => 'sq',
        'de' => 'de', 'deu' => 'de', 'ger' => 'de', 'german' => 'de', 'deutsch' => 'de', 'gjermanisht' => 'de',
    ];

    private const STATUS_ALIASES = ['draft' => 'draft', 'published' => 'published', 'publish' => 'published'];

    private const INSERT_CHUNK = 500;

    /** @var array<string, array<string, mixed>> */
    private array $groups = [];

    /** @var list<array<string, mixed>> */
    private array $rejected = [];

    /** @var array<string, array{string, int}> lowercase SKU => [group key, row number] */
    private array $skuOwners = [];

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly PlanEntitlementService $entitlements,
    ) {}

    /**
     * @param  array<int, string>  $mapping  destination field by column index
     *
     * @throws CatalogFileException
     */
    public function analyze(CatalogTransfer $transfer, array $mapping, string $source, ContentLanguage $defaultLanguage): void
    {
        $this->groups = [];
        $this->rejected = [];
        $this->skuOwners = [];

        $total = 0;
        $planRows = $this->entitlements->limit($transfer->user, 'import_rows_per_file');
        $path = Storage::disk('local')->path($transfer->stored_path);

        foreach ($this->reader->rows($path, $transfer->file_type) as $number => $cells) {
            if (array_filter($cells, fn ($cell) => $cell !== null) === []) {
                continue;
            }

            if (++$total > config('catalog.max_rows')) {
                throw new CatalogFileException('This file has more than '.number_format(config('catalog.max_rows')).' rows. Please split it into smaller files.');
            }

            if ($total > $planRows) {
                throw new CatalogFileException('Your plan imports up to '.number_format($planRows).' rows per file. Upgrade, or split the file into smaller ones.');
            }

            $this->addRow($number, $cells, $mapping, $defaultLanguage);
        }

        if ($total === 0) {
            throw new CatalogFileException('This file has no data rows.');
        }

        $this->matchExistingProducts($transfer->user, $source);
        $this->store($transfer, $mapping, $source, $defaultLanguage, $total);
    }

    /**
     * @param  array<int, ?string>  $cells
     * @param  array<int, string>  $mapping
     */
    private function addRow(int $number, array $cells, array $mapping, ContentLanguage $defaultLanguage): void
    {
        $row = [];

        foreach ($mapping as $index => $field) {
            $row[$field] = $cells[$index] ?? null;
        }

        $normalised = $this->normalise($row, $defaultLanguage);
        $errors = $this->validate($normalised);

        if ($errors !== []) {
            $this->reject($number, $row['original_name'] ?? null, $errors);

            return;
        }

        $this->addToGroup($number, $normalised);
    }

    /**
     * @param  array<string, ?string>  $row
     * @return array<string, mixed>
     */
    private function normalise(array $row, ContentLanguage $defaultLanguage): array
    {
        $hasContent = false;

        foreach (array_keys(ColumnMapper::CONTENT_FIELDS) as $field) {
            $hasContent = $hasContent || filled($row[$field] ?? null);
        }

        $rawLanguage = $row['language'] ?? null;
        $language = $rawLanguage === null ? $defaultLanguage->value : (self::LANGUAGE_ALIASES[mb_strtolower($rawLanguage)] ?? null);

        $rawStatus = $row['status'] ?? null;

        if (filled($row['keywords'] ?? null)) {
            $row['keywords'] = collect(preg_split('/[,;|]/', $row['keywords']))
                ->map(fn (string $keyword) => trim($keyword))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (filled($row['slug'] ?? null) || filled($row['title'] ?? null)) {
            $row['slug'] = Str::slug((string) ($row['slug'] ?? $row['title']), '-', $language ?? 'en') ?: null;
        }

        return [
            ...$row,
            'status' => $rawStatus === null ? null : (self::STATUS_ALIASES[mb_strtolower($rawStatus)] ?? $rawStatus),
            'language' => $language,
            'raw_language' => $rawLanguage,
            'has_content' => $hasContent,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validate(array $row): array
    {
        $validator = Validator::make($row, [
            'original_name' => ['required', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:191'],
            'sku' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:120'],
            'material' => ['nullable', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', Rule::in(array_values(self::STATUS_ALIASES))],
            'title' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'long_description' => ['nullable', 'string', 'max:20000'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'slug' => ['nullable', 'string', 'max:255'],
            'keywords' => ['nullable', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:64'],
        ], [
            'status.in' => 'Status must be draft or published.',
        ], [...ColumnMapper::destinations(), 'keywords.*' => 'Each keyword']);

        $errors = $validator->errors()->all();

        if ($row['language'] === null) {
            $errors[] = "Unsupported language “{$row['raw_language']}”. Use en, sq or de.";
        }

        return $errors;
    }

    /**
     * Rows for one product in different languages become one product with several translations.
     *
     * @param  array<string, mixed>  $row
     */
    private function addToGroup(int $number, array $row): void
    {
        $key = $this->groupKey($row);
        $sku = filled($row['sku'] ?? null) ? mb_strtolower($row['sku']) : null;

        if ($sku !== null && isset($this->skuOwners[$sku]) && $this->skuOwners[$sku][0] !== $key) {
            $this->reject($number, $row['original_name'], ["Duplicate SKU “{$row['sku']}”, also used on row {$this->skuOwners[$sku][1]} for a different product."]);

            return;
        }

        if (! isset($this->groups[$key])) {
            $this->groups[$key] = [
                'first_row' => $number,
                'row_count' => 0,
                'external_id' => $row['external_id'] ?? null,
                'product' => $this->productFields($row),
                'contents' => [],
                'match_type' => null,
                'has_identifier' => filled($row['external_id'] ?? null) || $sku !== null,
            ];
        } elseif (($error = $this->duplicateError($this->groups[$key], $row)) !== null) {
            $this->reject($number, $row['original_name'], [$error]);

            return;
        }

        if ($sku !== null) {
            $this->skuOwners[$sku] ??= [$key, $number];
        }

        $this->groups[$key]['row_count']++;

        if ($row['has_content']) {
            $this->groups[$key]['contents'][$row['language']] = $this->contentFields($row);
        }
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $row
     */
    private function duplicateError(array $group, array $row): ?string
    {
        if (! $row['has_content']) {
            return "Duplicate of the product on row {$group['first_row']}.";
        }

        if (isset($group['contents'][$row['language']])) {
            return "Duplicate {$row['language']} content for the product on row {$group['first_row']}.";
        }

        $sku = $row['sku'] ?? null;

        if (filled($sku) && filled($group['product']['sku']) && mb_strtolower($sku) !== mb_strtolower($group['product']['sku'])) {
            return "External ID “{$group['external_id']}” is used with a different SKU on row {$group['first_row']}.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function groupKey(array $row): string
    {
        return match (true) {
            filled($row['external_id'] ?? null) => 'external:'.mb_strtolower($row['external_id']),
            filled($row['sku'] ?? null) => 'sku:'.mb_strtolower($row['sku']),
            default => 'name:'.mb_strtolower($row['original_name']),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function productFields(array $row): array
    {
        return [
            'original_name' => $row['original_name'],
            'sku' => $row['sku'] ?? null,
            'category' => $row['category'] ?? null,
            'material' => $row['material'] ?? null,
            'details' => $row['details'] ?? null,
            'status' => $row['status'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function contentFields(array $row): array
    {
        return array_filter(
            array_intersect_key($row, ColumnMapper::CONTENT_FIELDS),
            fn ($value) => filled($value),
        );
    }

    /**
     * @param  list<string>  $errors
     */
    private function reject(int $number, ?string $name, array $errors): void
    {
        $this->rejected[] = ['first_row' => $number, 'name' => $name, 'errors' => $errors];
    }

    private function matchExistingProducts(User $user, string $source): void
    {
        foreach (array_chunk($this->groups, self::INSERT_CHUNK, preserve_keys: true) as $chunk) {
            $externalIds = array_values(array_filter(array_column($chunk, 'external_id')));
            $skus = array_values(array_filter(array_map(fn (array $group) => $group['product']['sku'], $chunk)));
            $names = array_values(array_map(fn (array $group) => $group['product']['original_name'], array_filter($chunk, fn (array $group) => ! $group['has_identifier'])));

            $knownExternal = ProductExternalReference::query()
                ->where('user_id', $user->id)->where('source', $source)->whereIn('external_id', $externalIds)
                ->pluck('external_id')->map(fn (string $value) => mb_strtolower($value))->flip();

            $knownSkus = $user->products()->whereIn('sku', $skus)->pluck('sku')->map(fn (string $value) => mb_strtolower($value))->flip();
            $knownNames = $user->products()->whereIn('original_name', $names)->pluck('original_name')->map(fn (string $value) => mb_strtolower($value))->flip();

            foreach ($chunk as $key => $group) {
                $this->groups[$key]['match_type'] = match (true) {
                    filled($group['external_id']) && $knownExternal->has(mb_strtolower($group['external_id'])) => 'external',
                    filled($group['product']['sku']) && $knownSkus->has(mb_strtolower($group['product']['sku'])) => 'sku',
                    ! $group['has_identifier'] && $knownNames->has(mb_strtolower($group['product']['original_name'])) => 'name',
                    default => null,
                };
            }
        }
    }

    /**
     * @param  array<int, string>  $mapping
     */
    private function store(CatalogTransfer $transfer, array $mapping, string $source, ContentLanguage $defaultLanguage, int $total): void
    {
        $rows = [];

        foreach ($this->groups as $group) {
            $rows[] = [
                'catalog_transfer_id' => $transfer->id,
                'first_row' => $group['first_row'],
                'row_count' => $group['row_count'],
                'status' => CatalogImportRow::VALID,
                'payload' => json_encode([
                    'external_id' => $group['external_id'],
                    'product' => $group['product'],
                    'contents' => $group['contents'],
                ], JSON_THROW_ON_ERROR),
                'errors' => null,
                'match_type' => $group['match_type'],
            ];
        }

        foreach ($this->rejected as $rejected) {
            $rows[] = [
                'catalog_transfer_id' => $transfer->id,
                'first_row' => $rejected['first_row'],
                'row_count' => 1,
                'status' => CatalogImportRow::INVALID,
                'payload' => json_encode(['product' => ['original_name' => $rejected['name']]], JSON_THROW_ON_ERROR),
                'errors' => json_encode($rejected['errors'], JSON_THROW_ON_ERROR),
                'match_type' => null,
            ];
        }

        DB::transaction(function () use ($transfer, $rows, $mapping, $source, $defaultLanguage, $total) {
            $transfer->rows()->delete();

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                CatalogImportRow::insert($chunk);
            }

            $transfer->update([
                'status' => CatalogTransferStatus::Validated,
                'total_records' => $total,
                'options' => [
                    ...$transfer->options,
                    'mapping' => $mapping,
                    'source' => $source,
                    'default_language' => $defaultLanguage->value,
                ],
                'summary' => [
                    'total_rows' => $total,
                    'valid_rows' => array_sum(array_column($this->groups, 'row_count')),
                    'invalid_rows' => count($this->rejected),
                    'products' => count($this->groups),
                    'possible_duplicates' => count(array_filter($this->groups, fn (array $group) => $group['match_type'] !== null)),
                ],
            ]);
        });
    }
}
