<?php

namespace App\Services\WooCommerce\Demo;

use App\Enums\AiGenerationStatus;
use App\Enums\BulkItemStatus;
use App\Enums\ContentLanguage;
use App\Models\AiGeneration;
use App\Models\BulkGenerationItem;
use App\Models\Product;
use App\Models\WooCommerceProductLink;
use App\Models\WooCommerceStore;
use App\Services\AI\ContentReviewQueue;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\ProductContentGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Puts demo drafts into the normal Content Review queue, either from fixed sample text
 * or, only when the user explicitly asks, from a real Gemini request.
 */
class WooCommerceDemoContentService
{
    public const SAMPLE_MODEL = 'sample-output';

    /** Title ending, then the two paragraphs of the long description. */
    private const COPY = [
        'Gaming Accessories' => ['for Everyday Gaming Setups', 'Designed to stay out of the way during long sessions, it brings a tidier, more considered look to any desk.', 'It suits both a dedicated gaming room and a shared workspace, and it is simple to set up and keep clean.'],
        'Home Decor' => ['for Warm, Lived-In Rooms', 'It adds a quiet point of interest to a room without competing with what is already there.', 'Place it where it can be seen every day, and let it settle into the character of your home.'],
        'Personalized Gifts' => ['as a Thoughtful Personal Gift', 'Made to mark a moment, it turns a name or a date into something worth keeping.', 'It works well for birthdays, anniversaries and thank-yous, when an ordinary gift would not feel like enough.'],
        'Office Accessories' => ['for a Calmer Workspace', 'It gives everyday items a proper place, so the desk stays clear for the work in front of you.', 'It fits home offices and shared desks alike, and it looks at home next to the rest of your setup.'],
        'Phone Accessories' => ['for Reliable Daily Use', 'It keeps your phone easy to reach and easy to use throughout the day.', 'It is a small, practical upgrade for commutes, errands and everything in between.'],
        'Keychains' => ['for Keys, Bags and Everyday Carry', 'It adds a small, personal detail to something you pick up every day.', 'Clip it to your keys or a bag strap, or give it as a modest gift for someone who likes well-made things.'],
    ];

    private const DEFAULT_COPY = ['for Everyday Use', 'It is a practical choice for everyday use.', 'It is simple to look after and easy to live with.'];

    public function __construct(
        private readonly ProductContentGenerator $generator,
        private readonly ContentReviewQueue $reviewQueue,
    ) {}

    /**
     * Makes no external request and uses no tokens or quota.
     *
     * @return int how many drafts were added to Content Review
     */
    public function generateSamples(WooCommerceStore $store, ?int $remoteId = null): int
    {
        $language = $this->languageFor($store);

        $links = $this->eligibleLinks($store, $remoteId)
            ->reject(fn (WooCommerceProductLink $link) => $this->hasPendingReview($link->product, $language));

        if ($links->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($store, $links, $language) {
            $user = $store->user;
            $batch = $this->reviewQueue->createBatch($user);

            foreach ($links as $link) {
                $generation = $user->aiGenerations()->create([
                    'product_id' => $link->product_id,
                    'product_name' => $link->product->original_name,
                    'provider' => AiGeneration::SAMPLE_PROVIDER,
                    'model' => self::SAMPLE_MODEL,
                    'language' => $language,
                    'status' => AiGenerationStatus::Succeeded,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                    'generated_content' => $this->sampleFor($link->product),
                ]);

                $this->reviewQueue->add($batch, $link->product, $generation);
            }
        });

        return $links->count();
    }

    /**
     * Sends a real request through the normal generator, with its quota, locking and
     * token tracking. The controller only calls this after the user has opted in.
     *
     * @throws AiGenerationException
     */
    public function generateWithGemini(WooCommerceStore $store, WooCommerceProductLink $link): BulkGenerationItem
    {
        $generation = $this->generator->generate($store->user, $link->product, $this->languageFor($store));

        return DB::transaction(fn () => $this->reviewQueue->add($this->reviewQueue->createBatch($store->user), $link->product, $generation));
    }

    /**
     * @return array<string, mixed> the same fields Gemini's validated output has
     */
    public function sampleFor(Product $product): array
    {
        $name = $product->original_name;
        [$ending, $first, $second] = self::COPY[$product->category] ?? self::DEFAULT_COPY;
        $title = "{$name} {$ending}";

        return [
            'title' => $title,
            'short_description' => "{$name}, chosen for people who care about the small things they use every day.",
            'long_description' => "{$first}\n\n{$second}",
            'meta_title' => Str::limit($name.' | Demo Store', 60, ''),
            'meta_description' => Str::limit("Discover the {$name}. {$first}", 155, ''),
            'slug' => Str::slug($title),
            'keywords' => array_values(array_filter([mb_strtolower($name), $product->category === null ? null : mb_strtolower($product->category)])),
        ];
    }

    /**
     * Read-only product types can't be synchronized, so a draft for them would lead nowhere.
     *
     * @return Collection<int, WooCommerceProductLink>
     */
    private function eligibleLinks(WooCommerceStore $store, ?int $remoteId): Collection
    {
        return $store->links()
            ->with('product')
            ->when($remoteId !== null, fn ($query) => $query->where('remote_id', $remoteId))
            ->get()
            ->filter(fn (WooCommerceProductLink $link) => ! $link->isReadOnly() && $link->product->demo_store_id === $store->id)
            ->values();
    }

    private function hasPendingReview(Product $product, ContentLanguage $language): bool
    {
        return BulkGenerationItem::query()
            ->where('product_id', $product->id)
            ->where('language', $language->value)
            ->where('status', BulkItemStatus::Generated->value)
            ->where('review_status', BulkGenerationItem::REVIEW_PENDING)
            ->exists();
    }

    private function languageFor(WooCommerceStore $store): ContentLanguage
    {
        return $store->content_language ?? ContentLanguage::English;
    }
}
