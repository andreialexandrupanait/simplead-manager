<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'sections',
        'section_overrides',
        'section_options',
        'company_name',
        'company_logo_path',
        'company_website',
        'primary_color',
        'intro_text',
        'closing_text',
        'is_default',
    ];

    protected $casts = [
        'sections' => 'array',
        'section_overrides' => 'array',
        'section_options' => 'array',
        'is_default' => 'boolean',
    ];

    public function getSectionTitle(string $key, string $lang = 'ro'): string
    {
        return $this->section_overrides[$key]['title'] ?? __("report.section_{$key}", [], $lang);
    }

    public function getSectionDescription(string $key, string $lang = 'ro'): ?string
    {
        if (isset($this->section_overrides[$key]['description'])) {
            return $this->section_overrides[$key]['description'];
        }

        $translationKey = "report.{$key}_description";
        $translated = __($translationKey, [], $lang);

        return $translated !== $translationKey ? $translated : null;
    }

    public function isSectionOptionEnabled(string $key, string $option): bool
    {
        return $this->section_options[$key][$option] ?? true;
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
