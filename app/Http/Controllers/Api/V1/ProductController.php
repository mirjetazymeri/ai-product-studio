<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Exceptions\ApiException;
use App\Http\Requests\Api\V1\ListProductsRequest;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Models\WooCommerceProductLink;
use App\Services\ProductExternalReferenceService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProductController extends ApiController
{
    private const RELATIONS = ['contents:id,product_id,language,source', 'externalReferences'];

    public function __construct(
        private readonly ProductService $products,
        private readonly ProductExternalReferenceService $references,
    ) {}

    public function index(ListProductsRequest $request): AnonymousResourceCollection
    {
        [$column, $direction] = $request->sorting();

        $products = $request->user()->products()
            ->with(self::RELATIONS)
            ->search($request->validated('search'))
            ->withStatus(ProductStatus::tryFrom((string) $request->validated('status')))
            ->when($request->validated('sku'), fn ($query, $sku) => $query->where('sku', $sku))
            ->when($request->validated('language'), fn ($query, $language) => $query->whereHas('contents', fn ($contents) => $contents->where('language', $language)))
            ->when($request->validated('external_source'), fn ($query) => $query->whereHas('externalReferences', fn ($references) => $references
                ->where('source', $request->validated('external_source'))
                ->where('external_id', $request->validated('external_id'))))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate($request->perPage())
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request) {
            $product = $this->products->create($request->user(), $request->productAttributes());

            if ($request->externalReference() !== null) {
                $this->references->assign($product, ...$request->externalReference());
            }

            return $product;
        });

        return (new ProductResource($product->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('api.v1.products.show', $product));
    }

    public function show(Request $request, string $product): ProductResource
    {
        return new ProductResource($this->ownedProduct($request, $product)->load(self::RELATIONS));
    }

    public function update(UpdateProductRequest $request): ProductResource
    {
        $product = $this->products->update($request->ownedProduct(), $request->productAttributes());

        return new ProductResource($product->load(self::RELATIONS));
    }

    /**
     * Only the local record goes. Nothing is ever sent to a connected store.
     */
    public function destroy(Request $request, string $product): Response
    {
        $model = $this->ownedProduct($request, $product, 'delete');

        if (! $request->boolean('unlink_stores') && $this->isLinkedToStore($model)) {
            throw new ApiException(409, 'linked_to_store', 'This product is linked to a connected store. Add unlink_stores=true to delete the local product and its link. The store\'s product isn\'t changed.');
        }

        $this->products->delete($model);

        return response()->noContent();
    }

    private function isLinkedToStore(Product $product): bool
    {
        return WooCommerceProductLink::query()->where('user_id', $product->user_id)->where('product_id', $product->id)->exists();
    }
}
