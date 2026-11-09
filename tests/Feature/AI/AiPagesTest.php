<?php

namespace Tests\Feature\AI;

use App\Enums\AiError;
use App\Models\AiGeneration;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_ai_studio_lists_only_the_users_products(): void
    {
        Product::factory()->for($this->user)->create(['original_name' => 'Mine']);
        Product::factory()->create(['original_name' => 'Not Mine']);

        $this->get(route('ai-studio'))->assertOk()->assertSee('Mine')->assertDontSee('Not Mine');
    }

    public function test_ai_studio_shows_the_selected_product_and_workspace(): void
    {
        $product = Product::factory()->for($this->user)->create(['details' => 'Brass hardware']);

        $this->get(route('ai-studio', ['product' => $product->id]))
            ->assertOk()
            ->assertSee('Brass hardware')
            ->assertSee(route('ai.generate', $product), false);
    }

    public function test_ai_studio_refuses_another_users_product(): void
    {
        $this->get(route('ai-studio', ['product' => Product::factory()->create()->id]))->assertForbidden();
    }

    public function test_history_shows_required_details_for_own_records_only(): void
    {
        AiGeneration::factory()->for($this->user)->create(['product_name' => 'My Bag']);
        AiGeneration::factory()->create(['product_name' => 'Someone Elses Bag']);

        $this->get(route('generation-history'))
            ->assertOk()
            ->assertSee(['My Bag', 'English', 'Gemini test-gemini-model', 'Succeeded', '150 tokens', 'Generated title'])
            ->assertDontSee('Someone Elses Bag');
    }

    public function test_history_shows_a_friendly_reason_for_failures(): void
    {
        AiGeneration::factory()->for($this->user)->failed(AiError::Timeout)->create();

        $this->get(route('generation-history'))->assertSee(['Failed', AiError::Timeout->message()]);
    }

    public function test_generated_content_is_escaped_in_history(): void
    {
        AiGeneration::factory()->for($this->user)->create([
            'generated_content' => ['title' => '<script>alert(1)</script>', 'keywords' => ['<b>x</b>']],
        ]);

        $this->get(route('generation-history'))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>x</b>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_history_reports_token_totals(): void
    {
        AiGeneration::factory()->for($this->user)->count(2)->create();

        $stats = $this->get(route('generation-history'))->viewData('stats');

        $this->assertSame(300, $stats['tokensToday']);
        $this->assertSame(300, $stats['tokensTotal']);
    }
}
