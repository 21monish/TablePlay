<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MenuCatalogSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $categories = collect([
            ['name' => 'Starters', 'description' => 'Small plates and shareable favourites.', 'sort_order' => 1],
            ['name' => 'Soups & Salads', 'description' => 'Light, fresh, and comforting bowls.', 'sort_order' => 2],
            ['name' => 'Main Course', 'description' => 'Comforting curries and chef specials.', 'sort_order' => 3],
            ['name' => 'Rice & Breads', 'description' => 'Fragrant rice and fresh Indian breads.', 'sort_order' => 4],
            ['name' => 'South Indian', 'description' => 'Dosas, tiffin favourites, and regional classics.', 'sort_order' => 5],
            ['name' => 'Kids & Quick Bites', 'description' => 'Familiar favourites and lighter portions.', 'sort_order' => 6],
            ['name' => 'Beverages', 'description' => 'Refreshing coolers, shakes, and hot drinks.', 'sort_order' => 7],
            ['name' => 'Desserts', 'description' => 'Sweet finishes for every table.', 'sort_order' => 8],
        ])->mapWithKeys(function (array $category) {
            $model = Category::firstOrCreate(['name' => $category['name']], [
                'description' => $category['description'],
                'sort_order' => $category['sort_order'],
                'is_active' => true,
            ]);

            return [$category['name'] => $model];
        });

        $items = [
            ['Starters', 'Paneer Tikka', 'Char-grilled cottage cheese with peppers and mint chutney.', 240, 'veg', 20],
            ['Starters', 'Crispy Corn', 'Golden corn tossed with herbs, peppers, and a light spice mix.', 190, 'veg', 15],
            ['Starters', 'Hara Bhara Kebab', 'Spinach and green pea patties served with mint dip.', 210, 'veg', 18],
            ['Starters', 'Chicken Tikka', 'Tandoor-roasted chicken with yoghurt and house spices.', 310, 'non_veg', 22],
            ['Starters', 'Chilli Chicken', 'Crispy chicken, peppers, and spring onion in chilli sauce.', 320, 'non_veg', 20],
            ['Starters', 'Veg Manchurian', 'Crisp vegetable dumplings tossed in a savoury Indo-Chinese sauce.', 220, 'veg', 18],
            ['Starters', 'Tandoori Mushroom', 'Marinated mushrooms roasted in the tandoor with peppers.', 235, 'veg', 20],
            ['Starters', 'Fish Amritsari', 'Ajwain-spiced fish fritters served with onion and mint chutney.', 345, 'non_veg', 22],

            ['Soups & Salads', 'Tomato Basil Soup', 'Roasted tomato soup finished with basil and cream.', 145, 'veg', 10],
            ['Soups & Salads', 'Sweet Corn Soup', 'Classic sweet corn broth with vegetables and spring onion.', 155, 'veg', 12],
            ['Soups & Salads', 'Chicken Manchow Soup', 'Spicy chicken broth with vegetables and crisp noodles.', 185, 'non_veg', 14],
            ['Soups & Salads', 'Garden Green Salad', 'Cucumber, tomato, lettuce, onion, and lemon dressing.', 135, 'veg', 8],
            ['Soups & Salads', 'Paneer Tikka Salad', 'Warm paneer tikka over greens with mint yoghurt dressing.', 210, 'veg', 14],

            ['Main Course', 'Dal Makhani', 'Slow-cooked black lentils finished with butter and cream.', 250, 'veg', 18],
            ['Main Course', 'Paneer Butter Masala', 'Cottage cheese in a smooth tomato and cashew gravy.', 295, 'veg', 20],
            ['Main Course', 'Kadai Vegetable', 'Seasonal vegetables cooked with peppers and kadai masala.', 270, 'veg', 20],
            ['Main Course', 'Butter Chicken', 'Tandoori chicken simmered in a rich tomato-butter sauce.', 360, 'non_veg', 25],
            ['Main Course', 'Chicken Handi', 'Tender chicken in a rustic onion and tomato gravy.', 345, 'non_veg', 25],
            ['Main Course', 'Egg Curry', 'Boiled eggs in a homestyle spiced onion gravy.', 245, 'egg', 18],
            ['Main Course', 'Palak Paneer', 'Cottage cheese simmered in a gently spiced spinach gravy.', 285, 'veg', 20],
            ['Main Course', 'Chole Masala', 'Chickpeas cooked with tomato, onion, and roasted spices.', 235, 'veg', 18],
            ['Main Course', 'Mutton Rogan Josh', 'Slow-cooked mutton in a fragrant Kashmiri-style gravy.', 445, 'non_veg', 35],
            ['Main Course', 'Coastal Fish Curry', 'Fish simmered in a tangy coconut and curry-leaf gravy.', 395, 'non_veg', 28],

            ['Rice & Breads', 'Veg Biryani', 'Basmati rice layered with vegetables, herbs, and saffron.', 280, 'veg', 25],
            ['Rice & Breads', 'Chicken Dum Biryani', 'Dum-cooked basmati rice layered with spiced chicken.', 360, 'non_veg', 30],
            ['Rice & Breads', 'Jeera Rice', 'Steamed basmati rice tempered with cumin.', 175, 'veg', 14],
            ['Rice & Breads', 'Butter Naan', 'Soft tandoor bread brushed with butter.', 65, 'veg', 8],
            ['Rice & Breads', 'Garlic Naan', 'Tandoor bread topped with garlic, coriander, and butter.', 80, 'veg', 9],
            ['Rice & Breads', 'Tandoori Roti', 'Whole-wheat flatbread baked in the tandoor.', 45, 'veg', 7],
            ['Rice & Breads', 'Steamed Basmati Rice', 'Fluffy long-grain basmati rice.', 155, 'veg', 12],
            ['Rice & Breads', 'Laccha Paratha', 'Layered whole-wheat bread roasted in the tandoor.', 70, 'veg', 9],

            ['South Indian', 'Masala Dosa', 'Crisp rice-lentil crepe filled with spiced potato.', 175, 'veg', 16],
            ['South Indian', 'Plain Dosa', 'Thin crisp rice-lentil crepe with sambar and chutneys.', 135, 'veg', 14],
            ['South Indian', 'Idli Sambar', 'Steamed rice cakes served with sambar and coconut chutney.', 125, 'veg', 10],
            ['South Indian', 'Medu Vada', 'Crisp lentil doughnuts with sambar and coconut chutney.', 135, 'veg', 12],
            ['South Indian', 'Vegetable Uttapam', 'Thick savoury pancake topped with vegetables.', 165, 'veg', 16],
            ['South Indian', 'Lemon Rice', 'South Indian rice tempered with lemon, peanuts, and curry leaves.', 165, 'veg', 14],

            ['Kids & Quick Bites', 'French Fries', 'Golden salted fries served with tomato ketchup.', 135, 'veg', 12],
            ['Kids & Quick Bites', 'Grilled Veg Sandwich', 'Grilled vegetables and cheese in toasted bread.', 175, 'veg', 14],
            ['Kids & Quick Bites', 'Cheese Corn Sandwich', 'Sweet corn and melted cheese in toasted bread.', 185, 'veg', 14],
            ['Kids & Quick Bites', 'Mini Margherita Pizza', 'Personal pizza with tomato, mozzarella, and herbs.', 225, 'veg', 18],
            ['Kids & Quick Bites', 'Chicken Nuggets', 'Crisp chicken bites served with fries and dip.', 245, 'non_veg', 16],

            ['Beverages', 'Fresh Lime Soda', 'Fresh lime, soda, and your choice of sweet or salted.', 110, 'veg', 5],
            ['Beverages', 'Mango Lassi', 'Chilled yoghurt blended with ripe mango.', 145, 'veg', 6],
            ['Beverages', 'Cold Coffee', 'Creamy chilled coffee topped with a light froth.', 165, 'veg', 7],
            ['Beverages', 'Masala Chai', 'Indian tea brewed with milk and aromatic spices.', 75, 'veg', 6],
            ['Beverages', 'Virgin Mojito', 'Lime, mint, sugar, and sparkling soda over ice.', 155, 'veg', 6],
            ['Beverages', 'Fresh Orange Juice', 'Freshly pressed orange juice served chilled.', 175, 'veg', 7],
            ['Beverages', 'South Indian Filter Coffee', 'Strong filter coffee blended with hot milk.', 95, 'veg', 6],

            ['Desserts', 'Gulab Jamun', 'Warm milk-solid dumplings in cardamom syrup.', 120, 'veg', 8],
            ['Desserts', 'Brownie with Ice Cream', 'Warm chocolate brownie with vanilla ice cream.', 210, 'veg', 10],
            ['Desserts', 'Matka Kulfi', 'Traditional slow-set cardamom and pistachio kulfi.', 150, 'veg', 5],
            ['Desserts', 'Rasmalai', 'Soft cottage-cheese dumplings in saffron-cardamom milk.', 165, 'veg', 6],
            ['Desserts', 'Gajar Halwa', 'Slow-cooked carrot pudding with nuts and cardamom.', 155, 'veg', 8],
            ['Desserts', 'Ice Cream Sundae', 'Vanilla and chocolate scoops with sauce and nuts.', 185, 'veg', 6],
        ];

        foreach ($items as [$category, $name, $description, $price, $foodType, $minutes]) {
            $item = MenuItem::firstOrCreate(['name' => $name], [
                'category_id' => $categories[$category]->id,
                'description' => $description,
                'price' => $price,
                'food_type' => $foodType,
                'preparation_minutes' => $minutes,
                'is_available' => true,
                'is_active' => true,
            ]);
            $allergens = match (true) {
                str_contains(strtolower($name.' '.$description), 'paneer'), str_contains(strtolower($name.' '.$description), 'cream'), str_contains(strtolower($name.' '.$description), 'milk'), str_contains(strtolower($name.' '.$description), 'cheese'), str_contains(strtolower($name.' '.$description), 'yoghurt'), str_contains(strtolower($name.' '.$description), 'butter') => ['milk'],
                str_contains(strtolower($name.' '.$description), 'naan'), str_contains(strtolower($name.' '.$description), 'bread'), str_contains(strtolower($name.' '.$description), 'sandwich'), str_contains(strtolower($name.' '.$description), 'pizza') => ['gluten'],
                default => [],
            };
            $item->update([
                'short_description' => $description,
                'ingredients' => $description,
                'allergens' => $allergens,
                'spice_level' => in_array($category, ['Starters', 'Main Course'], true) ? 'medium' : 'none',
                'calories' => match ($category) {
                    'Beverages' => 140, 'Desserts' => 320, 'Rice & Breads' => 360, default => 280,
                },
                'image_path' => '/images/menu/tableplay-platter-v1.png',
                'is_recommended' => in_array($name, ['Paneer Tikka', 'Butter Chicken', 'Chicken Dum Biryani', 'Masala Dosa', 'Mango Lassi'], true),
                'is_bestseller' => in_array($name, ['Paneer Butter Masala', 'Butter Chicken', 'Chicken Dum Biryani', 'Masala Dosa', 'Brownie with Ice Cream'], true),
                'customizations' => in_array($category, ['Starters', 'Main Course'], true) ? [[
                    'name' => 'Spice level',
                    'required' => true,
                    'choices' => [
                        ['name' => 'Mild', 'price' => 0],
                        ['name' => 'Medium', 'price' => 0],
                        ['name' => 'Hot', 'price' => 0],
                    ],
                ]] : [],
            ]);
        }
    }
}
