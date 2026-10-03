<?php

namespace Tests\Feature;

use App\Models\{Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ToastNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_flash_is_rendered_as_an_auto_dismissing_toast(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->withSession(['status' => 'Unused table permanently deleted.'])->get('/admin')
            ->assertOk()
            ->assertSee('toast toast--success', false)
            ->assertSee('data-timeout="4800"', false)
            ->assertSee('Unused table permanently deleted.')
            ->assertDontSee('alert alert--success', false);
    }

    public function test_validation_errors_are_rendered_as_longer_danger_toasts(): void
    {
        $admin = $this->admin();
        $errors = (new ViewErrorBag())->put('default', new MessageBag([
            'table' => ['Close the active table session before disabling this table.'],
        ]));

        $this->actingAs($admin)->withSession(['errors' => $errors])->get('/admin')
            ->assertOk()
            ->assertSee('toast toast--danger', false)
            ->assertSee('data-timeout="10000"', false)
            ->assertSee('Close the active table session before disabling this table.');
    }

    public function test_branded_confirmation_dialog_replaces_native_browser_boxes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('data-confirmation-dialog', false)
            ->assertSee('data-confirmation-accept', false)
            ->assertSee('data-confirmation-cancel', false);

        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertDoesNotMatchRegularExpression('/window\.(alert|confirm|prompt)\s*\(/', $javascript);
        $this->assertDoesNotMatchRegularExpression('/(^|[^A-Za-z])(alert|prompt)\s*\(/m', $javascript);
    }

    private function admin(): User
    {
        $role = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);

        return User::create([
            'role_id' => $role->id,
            'name' => 'Admin',
            'username' => 'admin',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
    }
}
