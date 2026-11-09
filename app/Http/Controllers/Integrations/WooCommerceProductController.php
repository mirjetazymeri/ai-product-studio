<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\WooCommerceStore;
use App\Services\WooCommerce\Languages\LanguageAdapterFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WooCommerceProductController extends Controller
{
    public function index(WooCommerceStore $store, LanguageAdapterFactory $languages): View
    {
        Gate::authorize('manage', $store);

        return view('integrations.products', [
            'store' => $store,
            'links' => $store->links()->with('product.contents')->orderByDesc('id')->paginate(25),
            'unavailableReason' => $store->isDisconnected() ? 'This store is disconnected.' : $languages->for($store)->unavailableReason(),
        ]);
    }
}
