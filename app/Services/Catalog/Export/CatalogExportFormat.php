<?php

namespace App\Services\Catalog\Export;

use App\Enums\ExportContentScope;
use App\Models\Product;

/**
 * The shape of an export file. A future store format, such as one for a specific
 * platform, is added as another implementation without changing the exporter.
 */
interface CatalogExportFormat
{
    /**
     * @return list<string>
     */
    public function headers(ExportContentScope $scope): array;

    /**
     * @param  list<string>  $languages
     * @param  array<string, array<string, mixed>>  $drafts  pending AI drafts for this product, keyed by language code
     * @return iterable<list<mixed>>
     */
    public function rows(Product $product, ExportContentScope $scope, array $languages, array $drafts): iterable;
}
