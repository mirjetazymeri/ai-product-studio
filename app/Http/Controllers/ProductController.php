<?php

namespace App\Http\Controllers;

use App\Enums\ContentLanguage;
use App\Enums\ProductStatus;
use App\Http\Requests\Products\ProductFilterRequest;
use App\Http\Requests\Products\SaveProductRequest;
use App\Models\Product;
use App\Models\WooCommerceProductLink;
use App\Services\ProductService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function index(ProductFilterRequest $request): View
    {
        Gate::authorize('viewAny', Product::class);

        return view('products.index', [
            'products' => $request->user()->products()
                ->with('contents:id,product_id,language')
                ->search($request->search())
                ->withStatus($request->status())
                ->sortedByCreation($request->sortDirection())
                ->paginate(10)
                ->withQueryString(),
            'isFiltered' => filled($request->search()) || $request->status() !== null,
            'statuses' => ProductStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Product::class);

        return view('products.create', [
            'categories' => $request->user()->products()
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category'),
        ]);
    }

    public function store(SaveProductRequest $request): RedirectResponse
    {
        try {
            $product = $this->products->create(
                $request->user(),
                $request->productAttributes(),
                $request->contents(),
            );
        } catch (QueryException $exception) {
            Log::error('Product could not be created.', ['user_id' => $request->user()->id, 'sql_state' => $exception->getCode()]);

            // withInput keeps the description and the reviewed fields on screen.
            return back()->withInput()->withErrors(['form' => 'We couldn\'t save this product. Please try again.']);
        }

        return to_route('products.show', $product)->with('status', 'Product created.');
    }

    public function show(Product $product): View
    {
        Gate::authorize('view', $product);

        return view('products.show', [
            'product' => $product->load('contents'),
            'languages' => ContentLanguage::cases(),
            'demoLink' => $product->isDemo() ? WooCommerceProductLink::query()->where('product_id', $product->id)->first() : null,
        ]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('products.edit', [
            'product' => $product->load('contents'),
            'statuses' => ProductStatus::cases(),
            'languages' => ContentLanguage::cases(),
        ]);
    }

    public function update(SaveProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->update($product, $request->productAttributes(), $request->contents());

        return to_route('products.show', $product)->with('status', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $this->products->delete($product);

        return to_route('products.index')->with('status', 'Product deleted.');
    }
}
