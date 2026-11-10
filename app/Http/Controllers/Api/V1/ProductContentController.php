<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContentLanguage;
use App\Http\Requests\Api\V1\SaveProductContentRequest;
use App\Http\Resources\Api\V1\ProductContentResource;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductContentController extends ApiController
{
    public function index(Request $request, string $product): AnonymousResourceCollection
    {
        return ProductContentResource::collection($this->ownedProduct($request, $product)->contents()->orderBy('language')->get());
    }

    public function show(Request $request, string $product, string $language): ProductContentResource
    {
        return new ProductContentResource($this->ownedProduct($request, $product)->contents()->where('language', $language)->firstOrFail());
    }

    public function update(SaveProductContentRequest $request, ProductService $products): ProductContentResource
    {
        return new ProductContentResource($products->saveManualContent(
            $request->ownedProduct(),
            ContentLanguage::from($request->route('language')),
            $request->contentFields(),
            $request->boolean('confirm_overwrite'),
        ));
    }
}
