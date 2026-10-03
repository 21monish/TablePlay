<?php

namespace Tests\Feature;

use App\Models\{Category, MenuItem, RestaurantSetting, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_branding_and_public_api_returns_urls(): void
    {
        Storage::fake('public');
        $role = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);
        $admin = User::create(['role_id' => $role->id, 'name' => 'Admin', 'username' => 'admin-branding', 'password' => 'secret-password', 'is_active' => true]);

        $this->actingAs($admin)->put('/admin/settings', [
            'restaurant_name' => 'TablePlay Kitchen', 'brand_color' => '#ef6a3a', 'secondary_color' => '#123c35',
            'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'tax_name' => 'GST', 'tax_rate' => 5,
            'game_duration_minutes' => 60, 'kitchen_refresh_seconds' => 4, 'device_offline_minutes' => 5,
            'restaurant_logo' => UploadedFile::fake()->image('restaurant.png', 512, 512),
            'app_logo' => UploadedFile::fake()->image('tableplay-app.png', 512, 512),
        ])->assertRedirect()->assertSessionHas('status');

        $settings = RestaurantSetting::firstOrFail();
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $settings->restaurant_logo_path));
        $this->assertSame($settings->customer_app_logo_path, $settings->staff_app_logo_path);
        $this->assertSame($settings->customer_app_logo_path, $settings->system_logo_path);
        $this->assertSame($settings->customer_app_logo_path, $settings->favicon_path);
        $this->getJson('/api/v1/branding')->assertOk()
            ->assertJsonPath('secondary_color', '#123c35')
            ->assertJsonStructure(['restaurant_logo_url', 'favicon_url', 'customer_app_logo_url', 'staff_app_logo_url', 'system_logo_url']);
    }

    public function test_branding_rejects_oversized_or_unsupported_files(): void
    {
        $role = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);
        $admin = User::create(['role_id' => $role->id, 'name' => 'Admin', 'username' => 'admin-validation', 'password' => 'secret-password', 'is_active' => true]);
        $this->actingAs($admin)->from('/admin/settings')->put('/admin/settings', [
            'restaurant_name' => 'TablePlay', 'brand_color' => '#ef6a3a', 'timezone' => 'Asia/Kolkata',
            'currency' => 'INR', 'tax_name' => 'GST', 'tax_rate' => 5, 'game_duration_minutes' => 60,
            'kitchen_refresh_seconds' => 4, 'device_offline_minutes' => 5,
            'app_logo' => UploadedFile::fake()->create('unsafe.svg', 10, 'image/svg+xml'),
        ])->assertRedirect('/admin/settings')->assertSessionHasErrors('app_logo');
    }

    public function test_uploaded_app_logo_is_served_as_a_non_cached_favicon(): void
    {
        Storage::fake('public');
        $role = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);
        $admin = User::create(['role_id' => $role->id, 'name' => 'Admin', 'username' => 'favicon-admin', 'password' => 'secret-password', 'is_active' => true]);

        $this->actingAs($admin)->put('/admin/settings', [
            'restaurant_name' => 'Favicon Cafe', 'brand_color' => '#ef6a3a', 'timezone' => 'Asia/Kolkata',
            'currency' => 'INR', 'tax_name' => 'GST', 'tax_rate' => 5, 'game_duration_minutes' => 60,
            'kitchen_refresh_seconds' => 4, 'device_offline_minutes' => 5,
            'app_logo' => UploadedFile::fake()->image('fresh-app-logo.png', 512, 512),
        ])->assertRedirect();

        $response = $this->get('/favicon.ico')->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
    }

    public function test_admin_can_replace_menu_photos_from_web_and_staff_app(): void
    {
        Storage::fake('public');
        $role = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);
        $admin = User::create(['role_id' => $role->id, 'name' => 'Admin', 'username' => 'photo-admin', 'password' => 'secret-password', 'is_active' => true]);
        $category = Category::create(['name' => 'Mains', 'sort_order' => 1, 'is_active' => true]);
        Storage::disk('public')->put('menu-items/old.png', 'old-image');
        $item = MenuItem::create([
            'category_id' => $category->id, 'name' => 'Paneer Tikka', 'price' => 250,
            'food_type' => 'veg', 'spice_level' => 'medium', 'image_path' => '/storage/menu-items/old.png',
            'is_available' => true, 'is_active' => true,
        ]);

        $this->actingAs($admin)->put("/admin/menu-items/{$item->id}", [
            'category_id' => $category->id, 'name' => 'Paneer Tikka Deluxe', 'price' => 275,
            'food_type' => 'veg', 'spice_level' => 'medium', 'preparation_minutes' => 18,
            'image' => UploadedFile::fake()->image('paneer.png', 800, 600),
        ])->assertRedirect()->assertSessionHas('status');

        $item->refresh();
        Storage::disk('public')->assertMissing('menu-items/old.png');
        Storage::disk('public')->assertExists(substr($item->image_path, 9));

        Sanctum::actingAs($admin);
        $this->post("/api/v1/admin/menu-items/{$item->id}/image", [
            'image' => UploadedFile::fake()->image('paneer-new.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('name', 'Paneer Tikka Deluxe');

        Storage::disk('public')->assertExists(substr($item->fresh()->image_path, 9));
        $this->assertDatabaseHas('audit_logs', ['action' => 'menu_item.image_updated.mobile']);
    }
}
