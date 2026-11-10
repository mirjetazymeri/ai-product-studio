<?php

namespace App\Services;

use App\Enums\AiError;
use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\Billing\Exceptions\PlanRestrictionException;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductService
{
    /**
     * Every way of creating a product passes here, so the plan limit can't be avoided.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $contents  keyed by language code
     * @param  bool  $countsTowardsPlan  false only for products of the simulated demo store
     *
     * @throws PlanRestrictionException
     */
    public function create(User $user, array $attributes, array $contents = [], bool $countsTowardsPlan = true): Product
    {
        $product = DB::transaction(function () use ($user, $attributes, $contents, $countsTowardsPlan) {
            if ($countsTowardsPlan) {
                app(PlanEntitlementService::class)->ensureCanCreateProduct($user);
            }

            $product = $user->products()->create($attributes);
            $this->saveContents($product, $contents);

            return $product;
        });

        Log::info('Product created.', ['product_id' => $product->id, 'user_id' => $user->id]);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $contents  keyed by language code
     */
    public function update(Product $product, array $attributes, array $contents = []): Product
    {
        DB::transaction(function () use ($product, $attributes, $contents) {
            $product->update($attributes);
            $this->saveContents($product, $contents);
        });

        Log::info('Product updated.', ['product_id' => $product->id, 'user_id' => $product->user_id]);

        return $product;
    }

    public function delete(Product $product): void
    {
        $product->delete();

        Log::info('Product deleted.', ['product_id' => $product->id, 'user_id' => $product->user_id]);
    }

    /**
     * Keep the single current record for a language in step with the given fields.
     *
     * All-blank fields remove the record. This is also the entry point future AI
     * generation will use, passing ContentSource::Ai.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveContent(
        Product $product,
        ContentLanguage $language,
        array $attributes,
        ContentSource $source = ContentSource::Manual,
    ): ?ProductContent {
        $content = $product->contents()->firstOrNew(['language' => $language->value]);

        if (collect($attributes)->every(fn ($value) => blank($value))) {
            if ($content->exists) {
                $content->delete();
            }

            return null;
        }

        $content->fill($attributes);

        // Re-saving a form unchanged must not relabel existing content.
        if (! $content->exists || $content->isDirty()) {
            $content->source = $source;
        }

        $content->save();

        return $content;
    }

    /**
     * Saves hand-written content. Anything that didn't start out hand-written, such as
     * approved AI content, is only replaced with explicit confirmation.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AiGenerationException when content exists and replacing it wasn't confirmed
     */
    public function saveManualContent(Product $product, ContentLanguage $language, array $attributes, bool $confirmOverwrite): ProductContent
    {
        return DB::transaction(function () use ($product, $language, $attributes, $confirmOverwrite) {
            // Serializes this with an approval arriving for the same product.
            Product::query()->lockForUpdate()->findOrFail($product->id);

            $existing = $product->contents()->where('language', $language->value)->first();

            if ($existing !== null && $existing->source !== ContentSource::Manual && ! $confirmOverwrite) {
                throw new AiGenerationException(AiError::ContentExists);
            }

            return $this->saveContent($product, $language, $attributes);
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $contents
     */
    private function saveContents(Product $product, array $contents): void
    {
        foreach ($contents as $code => $attributes) {
            $this->saveContent($product, ContentLanguage::from($code), $attributes);
        }
    }
}
