<?php

namespace Tests\Feature;

use App\Models\{CommercialPlan, DiningTable, GameSession, Order, Role, TableSession, User};
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameTimerBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_extension_reports_the_actual_reason_without_changing_the_timer(): void
    {
        [$user, $game, $visit, $order] = $this->fixture('billing');
        $before = $game->fresh()->getAttributes();
        $visitBefore = $visit->fresh()->getAttributes();
        $orderBefore = $order->fresh()->getAttributes();
        $message = 'This table is in billing. Game time cannot be extended.';

        $this->actingAs($user)->from(route('counter.index'))
            ->post(route('counter.games.extend', $game), ['minutes' => '15'])
            ->assertRedirect(route('counter.index'))
            ->assertSessionHasErrors(['game_session' => $message]);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/counter/game-sessions/'.$game->id.'/extend', ['minutes' => 15])
            ->assertUnprocessable()->assertJsonValidationErrors('game_session')
            ->assertJsonPath('errors.game_session.0', $message);

        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame($visitBefore, $visit->fresh()->getAttributes());
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('bills', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'game_session.extended']);
    }

    public function test_billing_card_is_locked_and_all_extension_controls_are_disabled(): void
    {
        [$user, $game] = $this->fixture('billing');
        $before = $game->fresh()->getAttributes();
        $response = $this->actingAs($user)->get(route('counter.index'));
        $response->assertOk()->assertSee('Locked during billing')
            ->assertSee('data-game-locked', false)->assertDontSee('data-expires=', false);
        $this->assertSame(0, $this->enabledExtensionButtons($response->getContent(), $game));
        $this->assertSame($before, $game->fresh()->getAttributes());
    }

    public function test_open_table_still_shows_a_countdown_and_enabled_extension_controls(): void
    {
        [$user, $game] = $this->fixture('open');
        $response = $this->actingAs($user)->get(route('counter.index'));
        $response->assertOk()->assertSee('data-game-timer', false)
            ->assertSee('data-expires=', false)->assertDontSee('Locked during billing');
        $this->assertSame(3, $this->enabledExtensionButtons($response->getContent(), $game));
        $this->post(route('counter.games.extend', $game), ['minutes' => '15'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($game->fresh()->expires_at->equalTo($game->expires_at->copy()->addMinutes(15)));
    }

    public function test_closed_visit_is_not_misreported_as_an_expired_timer(): void
    {
        [$user, $game] = $this->fixture('closed');
        $before = $game->fresh()->getAttributes();
        $this->actingAs($user)->post(route('counter.games.extend', $game), ['minutes' => '15'])
            ->assertSessionHasErrors(['game_session' => 'This table visit is not open. Game time cannot be extended.']);
        $this->assertSame($before, $game->fresh()->getAttributes());
    }

    private function enabledExtensionButtons(string $html, GameSession $game): int
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $action = route('counter.games.extend', $game);
        return $xpath->query('//form[@action="'.$action.'"]//button[not(@disabled)]')->length;
    }

    private function fixture(string $status): array
    {
        $this->travelTo(now()->startOfSecond());
        app(EntitlementService::class)->activate(CommercialPlan::where('slug', 'best')->firstOrFail());
        $role = Role::firstOrCreate(['name' => 'counter'], ['display_name' => 'Counter']);
        $user = User::create(['role_id' => $role->id, 'name' => 'Counter', 'username' => 'timer-counter', 'password' => 'test-password', 'is_active' => true]);
        $table = DiningTable::create(['table_code' => 'T01', 'table_name' => 'Table 1', 'capacity' => 4, 'status' => 'occupied']);
        $visit = TableSession::create(['session_code' => 'TIMER-VISIT', 'dining_table_id' => $table->id, 'guest_count' => 2, 'opened_at' => now()->subMinutes(17), 'status' => $status]);
        $order = Order::create(['order_number' => 'TIMER-ORDER', 'table_session_id' => $visit->id, 'order_sequence' => 1, 'status' => 'confirmed', 'subtotal' => 100, 'tax_amount' => 0, 'discount_amount' => 0, 'total_amount' => 100]);
        $game = GameSession::create(['table_session_id' => $visit->id, 'trigger_order_id' => $order->id, 'started_at' => now()->subMinutes(17), 'expires_at' => now()->addMinutes(43), 'status' => 'active']);
        return [$user, $game, $visit, $order];
    }
}
