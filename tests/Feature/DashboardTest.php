<?php

namespace Tests\Feature;

use App\Models\AiGeneration;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_statistics_come_from_the_database(): void
    {
        $user = User::factory()->create();
        Product::factory()->for($user)->count(2)->create();
        $published = Product::factory()->for($user)->published()->create(['original_name' => 'Recent One']);
        ProductContent::factory()->for($published)->create();

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Recent One');

        $this->assertSame(
            ['total' => 3, 'draft' => 2, 'published' => 1, 'generations' => 0],
            $response->viewData('stats'),
        );
    }

    public function test_ai_generations_count_only_the_users_successful_history(): void
    {
        $user = User::factory()->create();
        AiGeneration::factory()->for($user)->count(2)->create();
        AiGeneration::factory()->for($user)->failed()->create();
        AiGeneration::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $this->assertSame(2, $response->viewData('stats')['generations']);
    }

    public function test_a_new_user_sees_empty_states(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No products yet');
    }

    public function test_ai_pages_render_their_empty_states(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('ai-studio'))->assertOk()->assertSee('Add a product first');
        $this->get(route('generation-history'))->assertOk()->assertSee('No generations yet');
    }
}
