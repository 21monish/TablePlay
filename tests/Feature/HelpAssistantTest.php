<?php

namespace Tests\Feature;

use App\Models\{Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_use_the_staff_help_assistant(): void
    {
        $this->post('/help/chat', ['message' => 'How do I confirm an order?'])
            ->assertRedirect('/login');
    }

    public function test_admin_receives_pairing_guidance_and_an_accessible_action(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->postJson('/help/chat', ['message' => 'How do I pair a tablet?'])
            ->assertOk()
            ->assertJsonPath('title', 'Tablet pairing')
            ->assertJsonPath('action.label', 'Open Tables & devices')
            ->assertJsonPath('action.url', route('admin.tables'))
            ->assertJsonPath('offline', true)
            ->assertJsonCount(4, 'steps');
    }

    public function test_counter_receives_billing_guidance_without_admin_links(): void
    {
        $counter = $this->user('counter');

        $response = $this->actingAs($counter)->postJson('/help/chat', ['message' => 'How do I generate a bill and take cash?'])
            ->assertOk()
            ->assertJsonPath('title', 'Billing and payment')
            ->assertJsonPath('action.url', route('counter.index'));

        $this->assertStringNotContainsString('/admin/', $response->getContent());
    }

    public function test_kitchen_question_is_limited_to_kitchen_guidance(): void
    {
        $kitchen = $this->user('kitchen');

        $this->actingAs($kitchen)->postJson('/help/chat', ['message' => 'What does overdue mean and how do I enable sound?'])
            ->assertOk()
            ->assertJsonPath('title', 'Kitchen timers and sound')
            ->assertJsonPath('action.url', route('kitchen.index'));

        $this->actingAs($kitchen)->postJson('/help/chat', ['message' => 'Pair a tablet'])
            ->assertOk()
            ->assertJsonPath('title', 'Kitchen guide')
            ->assertJsonPath('action', null);
    }

    public function test_common_typing_mistakes_still_find_the_right_guide(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->postJson('/help/chat', ['message' => 'How can I pair the tablat devce?'])
            ->assertOk()
            ->assertJsonPath('title', 'Tablet pairing');
    }

    private function user(string $roleName): User
    {
        $role = Role::create(['name' => $roleName, 'display_name' => ucfirst($roleName)]);

        return User::create([
            'role_id' => $role->id,
            'name' => ucfirst($roleName).' User',
            'username' => $roleName,
            'password' => 'secret-password',
            'is_active' => true,
        ]);
    }
}
