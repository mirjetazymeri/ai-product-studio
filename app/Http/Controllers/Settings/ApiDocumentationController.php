<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Shows the same documents that are in the repository, so there is one copy to maintain.
 */
class ApiDocumentationController extends Controller
{
    private const PAGES = ['README.md' => 'API Documentation', 'authentication.md' => 'API Authentication', 'examples.md' => 'API Examples'];

    public function show(string $page): View
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        return view('settings.api-docs', [
            'title' => self::PAGES[$page],
            'html' => Str::markdown((string) file_get_contents(base_path("docs/api/{$page}")), ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]);
    }

    public function openApi(): Response
    {
        return response((string) file_get_contents(base_path('docs/api/openapi.yaml')), 200, [
            'Content-Type' => 'text/yaml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="openapi.yaml"',
        ]);
    }
}
