<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\SaveExternalReferenceRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\ProductExternalReferenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExternalReferenceController extends ApiController
{
    public function __construct(private readonly ProductExternalReferenceService $references) {}

    public function update(SaveExternalReferenceRequest $request): ProductResource
    {
        $product = $request->ownedProduct();

        $this->references->assign($product, $request->route('source'), $request->validated('external_id'));

        return new ProductResource($product->load(['contents:id,product_id,language,source', 'externalReferences']));
    }

    public function destroy(Request $request, string $product, string $source): Response
    {
        $this->references->remove($this->ownedProduct($request, $product, 'update'), $source);

        return response()->noContent();
    }
}
