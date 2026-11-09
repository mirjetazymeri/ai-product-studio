<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransferHistoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('catalog.history', [
            'transfers' => $request->user()->catalogTransfers()->latest()->latest('id')->paginate(20),
        ]);
    }
}
