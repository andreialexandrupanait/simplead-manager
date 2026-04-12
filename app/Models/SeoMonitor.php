<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoMonitor extends Model
{
    protected $fillable = ['site_id','is_active','interval_minutes','next_audit_at','last_audit_at','max_pages','max_external_link_checks','sitemap_url','audit_config'];
    protected function casts(): array { return ['is_active'=>'boolean','next_audit_at'=>'datetime','last_audit_at'=>'datetime','audit_config'=>'array']; }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function audits(): HasMany { return $this->hasMany(SeoAudit::class, 'site_id', 'site_id'); }
    public function scopeActive(Builder $query): Builder { return $query->where('is_active', true); }
    public function scopeDue(Builder $query): Builder { return $query->where(fn (Builder $q) => $q->whereNull('next_audit_at')->orWhere('next_audit_at', '<=', now())); }
}\n