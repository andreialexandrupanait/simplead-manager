<?php

namespace Database\Seeders;

use App\Models\ReportTemplate;
use Illuminate\Database\Seeder;

class ReportTemplateSeeder extends Seeder
{
    public function run(): void
    {
        ReportTemplate::firstOrCreate(
            ['name' => 'Monthly Maintenance Report'],
            [
                'description' => 'Comprehensive monthly report covering all site health metrics, updates, uptime, backups, analytics, search console, performance, and link status.',
                'sections' => [
                    'overview',
                    'updates',
                    'uptime',
                    'backups',
                    'analytics',
                    'search_console',
                    'performance',
                    'links',
                ],
                'company_name' => 'SimpleAd',
                'primary_color' => '#7C3AED',
                'is_default' => true,
                'intro_text' => "Acest raport oferă o privire de ansamblu completă asupra sănătății, performanței și activităților de mentenanță ale website-ului dumneavoastră pentru perioada de raportare.\n\nToate datele sunt colectate automat din sistemele de monitorizare ale site-ului, oferind o imagine precisă a modului în care funcționează website-ul dumneavoastră.",
                'closing_text' => "Vă mulțumim pentru încrederea acordată serviciilor noastre. Dacă aveți întrebări despre acest raport sau doriți să discutăm despre oportunități de optimizare, nu ezitați să ne contactați.\n\nRămânem dedicați menținerii website-ului dumneavoastră sigur, rapid și actualizat.",
            ]
        );
    }
}
