<?php
declare(strict_types=1);
namespace App\Models;

use App\Enums\SeoIssueCategory;
use App\Enums\SeoIssueSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoIssue extends Model
{
    protected $fillable = ['site_id','seo_audit_id','category','severity','title','description','url','recommendation','meta','resolved_at'];
    protected function casts(): array { return ['category'=>SeoIssueCategory::class,'severity'=>SeoIssueSeverity::class,'meta'=>'array','resolved_at'=>'datetime']; }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function audit(): BelongsTo { return $this->belongsTo(SeoAudit::class, 'seo_audit_id'); }
    public function scopeActive(Builder $query): Builder { return $query->whereNull('resolved_at'); }
    public function scopeBySeverity(Builder $query, SeoIssueSeverity $severity): Builder { return $query->where('severity', $severity); }
    public function scopeByCategory(Builder $query, SeoIssueCategory $category): Builder { return $query->where('category', $category); }
}\n