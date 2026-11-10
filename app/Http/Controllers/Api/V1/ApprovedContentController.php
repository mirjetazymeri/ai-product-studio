<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Exceptions\ApiException;
use App\Http\Resources\Api\V1\ProductContentResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saved content whose source shows that a person wrote or approved it. Drafts live in
 * generation records until approved, so they can't appear here.
 */
class ApprovedContentController extends ApiController
{
    public function __invoke(Request $request, string $product): ProductContentResource
    {
        $model = $this->ownedProduct($request, $product);

        $validated = $request->validate(['language' => ['required', Rule::enum(ContentLanguage::class)]]);

        $content = $model->contents()->where('language', $validated['language'])->first();

        if ($content === null || ! $content->source->isApprovedForSync() || $content->source === ContentSource::Sample) {
            throw new ApiException(404, 'no_approved_content', 'This product has no approved content in that language.');
        }

        return new ProductContentResource($content);
    }
}
