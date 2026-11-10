<?php

namespace Tests\Feature\Api;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    /**
     * Reads the specification line by line, which is enough for its fixed layout and
     * avoids adding a YAML dependency for one test.
     *
     * @return array<string, ?string> required ability by "METHOD path"
     */
    private function documentedOperations(): array
    {
        $path = null;
        $operation = null;
        $operations = [];

        foreach (file(base_path('docs/api/openapi.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === 'components:') {
                break;
            }

            if (preg_match('#^  (/\S*):$#', $line, $matches) === 1) {
                $path = preg_replace('/\{[^}]+\}/', '{}', $matches[1]);
            } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete):$/', $line, $matches) === 1) {
                $operation = strtoupper($matches[1]).' '.$path;
                $operations[$operation] = null;
            } elseif ($operation !== null && preg_match('/^      x-token-ability: (\S+)$/', $line, $matches) === 1) {
                $operations[$operation] = $matches[1];
            }
        }

        return $operations;
    }

    /**
     * @return array<string, ?string>
     */
    private function implementedOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $path = preg_replace('/\{[^}]+\}/', '{}', '/'.substr($route->uri(), strlen('api/v1/')));
            $ability = collect($route->gatherMiddleware())->first(fn ($name) => is_string($name) && str_starts_with($name, 'abilities:'));

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $operations["{$method} {$path}"] = $ability === null ? null : substr($ability, strlen('abilities:'));
            }
        }

        return $operations;
    }

    public function test_the_specification_matches_every_route_and_ability(): void
    {
        $documented = $this->documentedOperations();
        $implemented = $this->implementedOperations();

        ksort($documented);
        ksort($implemented);

        $this->assertSame($implemented, $documented);
    }

    public function test_the_readme_lists_every_endpoint(): void
    {
        $readme = (string) file_get_contents(base_path('docs/api/README.md'));

        foreach (array_keys($this->implementedOperations()) as $operation) {
            [$method, $path] = explode(' ', $operation);
            $pattern = preg_quote($method.' '.$path, '#');
            $pattern = str_replace('\{\}', '\{[a-z]+\}', $pattern);

            $this->assertMatchesRegularExpression("#`{$pattern}(\?language=)?`#", $readme, $operation);
        }
    }

    public function test_the_postman_collection_is_valid_and_has_no_real_credentials(): void
    {
        $collection = json_decode((string) file_get_contents(base_path('docs/api/postman/AI-Product-Studio.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
        $environment = json_decode((string) file_get_contents(base_path('docs/api/postman/AI-Product-Studio.postman_environment.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('{{api_token}}', $collection['auth']['bearer'][0]['value']);

        $variables = collect($environment['values'])->pluck('value', 'key');
        $this->assertSame('', $variables['api_token']);
        $this->assertEqualsCanonicalizing(['base_url', 'api_token', 'product_id', 'generation_id', 'export_id'], $variables->keys()->all());

        $names = array_column($collection['item'], 'name');

        foreach (['Get authenticated user', 'List products', 'Create product', 'Get product', 'Update product', 'Analyze product with AI', 'Generate content', 'Check generation status', 'Run SEO analysis', 'Fetch approved content', 'Create export'] as $required) {
            $this->assertContains($required, $names);
        }
    }

    public function test_documentation_contains_no_credentials(): void
    {
        foreach (glob(base_path('docs/api/*')) as $file) {
            if (is_file($file)) {
                $this->assertDoesNotMatchRegularExpression('/\d+\|aps_[A-Za-z0-9]{10,}|AIza[0-9A-Za-z_-]{20,}|ck_[0-9a-f]{20,}/', (string) file_get_contents($file), $file);
            }
        }
    }
}
