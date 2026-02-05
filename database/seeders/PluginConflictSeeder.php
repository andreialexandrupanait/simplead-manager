<?php

namespace Database\Seeders;

use App\Models\PluginConflict;
use Illuminate\Database\Seeder;

class PluginConflictSeeder extends Seeder
{
    public function run(): void
    {
        $conflicts = [
            // SEO conflicts
            ['wordpress-seo', 'all-in-one-seo-pack', 'functionality', 'Both plugins manage SEO meta tags, sitemaps, and schema markup. Running both causes duplicate meta tags and conflicting sitemaps.', 'high'],
            ['wordpress-seo', 'seo-by-rank-math', 'functionality', 'Both plugins manage SEO meta tags and sitemaps. Running both causes duplicate output and configuration conflicts.', 'high'],
            ['all-in-one-seo-pack', 'seo-by-rank-math', 'functionality', 'Both plugins provide the same SEO functionality. Running both leads to duplicate meta tags.', 'high'],

            // Caching conflicts
            ['w3-total-cache', 'wp-super-cache', 'performance', 'Both plugins implement page caching. Running both causes caching conflicts and potential white screens.', 'critical'],
            ['w3-total-cache', 'wp-fastest-cache', 'performance', 'Both plugins implement page caching. Running both leads to conflicts and performance degradation.', 'critical'],
            ['litespeed-cache', 'w3-total-cache', 'performance', 'Both plugins implement page caching and object caching. Conflicts in cache management.', 'high'],
            ['litespeed-cache', 'wp-super-cache', 'performance', 'Both plugins implement page caching. LiteSpeed Cache should be used alone on LiteSpeed servers.', 'high'],

            // Security conflicts
            ['wordfence', 'sucuri-scanner', 'performance', 'Both plugins implement firewall rules and file scanning. Running both causes high server load and potential rule conflicts.', 'high'],
            ['wordfence', 'all-in-one-wp-security-and-firewall', 'performance', 'Both plugins implement firewall and login protection. Running both causes redundant processing.', 'medium'],

            // Page builder conflicts
            ['elementor', 'js_composer', 'functionality', 'Both are page builders that modify post content. Using both on the same page causes rendering conflicts.', 'high'],
            ['elementor', 'developer-developer', 'functionality', 'Both are page builders. Running both increases page load and can cause editor conflicts.', 'medium'],

            // Image optimization conflicts
            ['wp-smushit', 'developer-developer', 'performance', 'Both plugins optimize images. Running both causes double compression and potential quality loss.', 'medium'],
            ['wp-smushit', 'developer-developer', 'performance', 'Both plugins handle image compression. Running both can degrade image quality.', 'medium'],

            // E-commerce conflicts
            ['woocommerce', 'developer-developer', 'functionality', 'Both are e-commerce solutions with overlapping checkout and payment functionality.', 'high'],

            // Minification conflicts
            ['autoptimize', 'developer-developer', 'performance', 'Both plugins minify and combine CSS/JS files. Running both causes double-minification and potential breakage.', 'medium'],

            // SMTP conflicts
            ['wp-mail-smtp', 'developer-developer', 'functionality', 'Both plugins override WordPress mail handling. Running both causes email delivery conflicts.', 'high'],

            // Redirect conflicts
            ['redirection', 'developer-developer', 'functionality', 'Both plugins manage URL redirects. Running both can cause redirect loops.', 'medium'],

            // Anti-spam conflicts
            ['akismet', 'developer-developer', 'performance', 'Both plugins filter spam comments. Running both is redundant and adds unnecessary processing.', 'low'],

            // Editor conflicts
            ['classic-editor', 'developer-developer', 'functionality', 'Both plugins disable the Gutenberg editor. Running both is redundant.', 'low'],
        ];

        foreach ($conflicts as [$a, $b, $type, $desc, $severity]) {
            PluginConflict::firstOrCreate(
                ['plugin_a_slug' => $a, 'plugin_b_slug' => $b],
                [
                    'conflict_type' => $type,
                    'description' => $desc,
                    'severity' => $severity,
                ]
            );
        }
    }
}
