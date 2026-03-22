<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteTheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SiteTheme>
 */
class SiteThemeFactory extends Factory
{
    protected $model = SiteTheme::class;

    /**
     * The list of realistic WordPress theme names.
     */
    private static array $themes = [
        ['slug' => 'astra', 'name' => 'Astra'],
        ['slug' => 'generatepress', 'name' => 'GeneratePress'],
        ['slug' => 'oceanwp', 'name' => 'OceanWP'],
        ['slug' => 'kadence', 'name' => 'Kadence'],
        ['slug' => 'hello-elementor', 'name' => 'Hello Elementor'],
        ['slug' => 'twentytwentyfour', 'name' => 'Twenty Twenty-Four'],
        ['slug' => 'twentytwentyfive', 'name' => 'Twenty Twenty-Five'],
        ['slug' => 'flavflavor', 'name' => 'flavor flavor'],
        ['slug' => 'developer', 'name' => 'Developer'],
        ['slug' => 'developer-developer', 'name' => 'developer developer'],
        ['slug' => 'developer developer-developer', 'name' => 'developer developer developer'],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $theme = fake()->randomElement(self::$themes);

        return [
            'site_id' => Site::factory(),
            'slug' => $theme['slug'],
            'name' => $theme['name'],
            'version' => fake()->numerify('#.#.#'),
            'author' => fake()->company(),
            'author_uri' => fake()->optional()->url(),
            'description' => fake()->sentence(),
            'is_active' => false,
            'is_child_theme' => false,
            'parent_theme' => null,
            'has_update' => false,
            'update_version' => null,
            'screenshot_url' => null,
            'auto_update' => fake()->boolean(30),
        ];
    }

    /**
     * Indicate the theme is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate the theme is a child theme.
     */
    public function childTheme(string $parentSlug = 'astra'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_child_theme' => true,
            'parent_theme' => $parentSlug,
        ]);
    }

    /**
     * Indicate the theme has an available update.
     */
    public function withUpdate(string $version = '2.0.0'): static
    {
        return $this->state(fn (array $attributes) => [
            'has_update' => true,
            'update_version' => $version,
        ]);
    }
}
