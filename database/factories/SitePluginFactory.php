<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SitePlugin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SitePlugin>
 */
class SitePluginFactory extends Factory
{
    protected $model = SitePlugin::class;

    /**
     * The list of realistic WordPress plugin names.
     */
    private static array $plugins = [
        ['slug' => 'woocommerce', 'name' => 'WooCommerce', 'file' => 'woocommerce/woocommerce.php'],
        ['slug' => 'yoast-seo', 'name' => 'Yoast SEO', 'file' => 'wordpress-seo/wp-seo.php'],
        ['slug' => 'contact-form-7', 'name' => 'Contact Form 7', 'file' => 'contact-form-7/wp-contact-form-7.php'],
        ['slug' => 'elementor', 'name' => 'Elementor', 'file' => 'elementor/elementor.php'],
        ['slug' => 'wordfence', 'name' => 'Wordfence Security', 'file' => 'wordfence/wordfence.php'],
        ['slug' => 'akismet', 'name' => 'Akismet Anti-Spam', 'file' => 'akismet/akismet.php'],
        ['slug' => 'wp-super-cache', 'name' => 'WP Super Cache', 'file' => 'wp-super-cache/wp-cache.php'],
        ['slug' => 'updraftplus', 'name' => 'UpdraftPlus Backup', 'file' => 'updraftplus/updraftplus.php'],
        ['slug' => 'classic-editor', 'name' => 'Classic Editor', 'file' => 'classic-editor/classic-editor.php'],
        ['slug' => 'wpforms-lite', 'name' => 'WPForms Lite', 'file' => 'wpforms-lite/wpforms.php'],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plugin = fake()->randomElement(self::$plugins);

        return [
            'site_id' => Site::factory(),
            'file' => $plugin['file'],
            'slug' => $plugin['slug'],
            'name' => $plugin['name'],
            'version' => fake()->numerify('#.#.#'),
            'author' => fake()->company(),
            'author_uri' => fake()->optional()->url(),
            'plugin_uri' => fake()->optional()->url(),
            'description' => fake()->sentence(),
            'is_active' => true,
            'has_update' => false,
            'update_version' => null,
            'requires_wp' => fake()->randomElement(['6.0', '6.2', '6.4', '6.5']),
            'requires_php' => fake()->randomElement(['7.4', '8.0', '8.1', '8.2']),
            'auto_update' => fake()->boolean(30),
            'wp_org_last_updated' => fake()->optional()->dateTimeBetween('-1 year', '-1 week'),
            'is_on_wp_org' => true,
            'is_abandoned' => false,
            'is_closed' => false,
            'closed_reason' => null,
            'abandoned_checked_at' => fake()->optional()->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Indicate the plugin is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate the plugin is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate the plugin has an available update.
     */
    public function withUpdate(string $version = '2.0.0'): static
    {
        return $this->state(fn (array $attributes) => [
            'has_update' => true,
            'update_version' => $version,
        ]);
    }

    /**
     * Indicate the plugin is abandoned.
     */
    public function abandoned(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_abandoned' => true,
            'wp_org_last_updated' => fake()->dateTimeBetween('-3 years', '-2 years'),
            'abandoned_checked_at' => now(),
        ]);
    }

    /**
     * Indicate the plugin is closed on wp.org.
     */
    public function closed(string $reason = 'Security issue'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_closed' => true,
            'closed_reason' => $reason,
        ]);
    }

    /**
     * Indicate the plugin is not on wp.org (premium/custom).
     */
    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_on_wp_org' => false,
            'is_abandoned' => false,
            'is_closed' => false,
            'wp_org_last_updated' => null,
        ]);
    }
}
