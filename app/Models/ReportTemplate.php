<?php

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
        'is_default' => 'boolean',
    ];

    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
