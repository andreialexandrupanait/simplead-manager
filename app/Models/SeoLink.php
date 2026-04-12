<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoLink extends Model
{
    protected $fillable = ['seo_audit_id','seo_page_id','target_url','target_url_hash','type','rel','anchor_text','status_code','is_broken'];
    protected function casts(): array { return ['is_broken'=>'boolean']; }
    public function audit(): BelongsTo { return $this->belongsTo(SeoAudit::class, 'seo_audit_id'); }
    public function page(): BelongsTo { return $this->belongsTo(SeoPage::class, 'seo_page_id'); }
    public function scopeBroken(Builder $query): Builder { return $query->where('is_broken', true); }
    public function scopeInternal(Builder $query): Builder { return $query->where('type', 'internal'); }
    public function scopeExternal(Builder $query): Builder { return $query->where('type', 'external'); }
}\n