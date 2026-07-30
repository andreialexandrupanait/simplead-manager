<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Faza D: seed the 82 methodology checks from database/data/audit-checks-v2.json.
 * Retained as migration plumbing only: the audit module (models, services, UI)
 * was removed in Faza 1.2, but the create migration 2026_07_23_000001 still
 * invokes this seeder in up(), so it must keep the audit_checks table populated
 * on a fresh migrate. It writes via the query builder (no Eloquent model) since
 * the AuditCheck model no longer exists; the table itself is dropped by the
 * Faza-1.2 drop migration that runs afterwards. Idempotent: upserts on `key`.
 */
class AuditChecksSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/audit-checks-v2.json');
        if (! is_file($path)) {
            throw new \RuntimeException("Missing audit checks data file: {$path}");
        }

        /** @var array{sections: array<int,array<string,mixed>>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $rows = [];
        $order = 0;

        foreach ($data['sections'] as $section) {
            $groups = $section['subsections'] ?? [['id' => null, 'name' => null, 'checks' => $section['checks'] ?? []]];

            foreach ($groups as $group) {
                foreach ($group['checks'] ?? [] as $check) {
                    // A check carries either a single `source` or a `sources` array.
                    $sources = $check['sources'] ?? (isset($check['source']) ? [$check['source']] : []);

                    $rows[] = [
                        'key' => $check['id'],
                        'section_key' => $section['key'],
                        'section_nr' => $section['id'],
                        'section_name' => $section['name'],
                        'subsection_id' => $group['id'] ?? null,
                        'subsection_name' => $group['name'] ?? null,
                        'question' => $check['question'],
                        'sources' => json_encode($sources, JSON_UNESCAPED_UNICODE),
                        'team' => $check['team'] ?? null,
                        'lenses' => isset($check['lenses']) ? json_encode($check['lenses'], JSON_UNESCAPED_UNICODE) : null,
                        'recommendation_template' => $check['recommendationTemplate'] ?? null,
                        'applicability' => $check['applicability'] ?? null,
                        'sort_order' => $order++,
                        'updated_at' => now(),
                    ];
                }
            }
        }

        // Upsert on the natural key so re-seeding refreshes definitions without
        // touching audit_check_results (which FK the check id, stable per key).
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('audit_checks')->upsert(
                array_map(fn ($r) => $r + ['created_at' => now()], $chunk),
                ['key'],
                ['section_key', 'section_nr', 'section_name', 'subsection_id', 'subsection_name',
                    'question', 'sources', 'team', 'lenses', 'recommendation_template', 'applicability',
                    'sort_order', 'updated_at'],
            );
        }
    }
}
