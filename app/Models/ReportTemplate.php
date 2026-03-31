<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array $sections
 * @property array|null $section_overrides
 * @property array|null $section_options
 * @property string|null $company_name
 * @property string|null $company_logo_path
 * @property string|null $company_website
 * @property string $primary_color
 * @property string|null $intro_text
 * @property string|null $closing_text
 * @property bool $is_default
 * @property string $language
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Site> $sites
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReportSchedule> $schedules
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Report> $reports
 */
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
        'language',
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

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
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
