<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoPage extends Model
{
    protected $fillable = ['seo_audit_id','site_id','url','url_hash','status_code','depth','content_type','title','title_length','meta_description','meta_description_length','h1_tags','heading_structure','word_count','image_count','images_without_alt','canonical_url','is_self_canonical','meta_robots','is_indexable','in_sitemap','blocked_by_robots','internal_link_count','external_link_count','inbound_internal_links','redirect_target','redirect_chain_length','page_size_bytes','ttfb_seconds','structured_data_types','og_tags','twitter_tags','has_viewport_meta','meta'];
    protected function casts(): array { return ['h1_tags'=>'array','heading_structure'=>'array','structured_data_types'=>'array','og_tags'=>'array','twitter_tags'=>'array','meta'=>'array','is_self_canonical'=>'boolean','is_indexable'=>'boolean','in_sitemap'=>'boolean','blocked_by_robots'=>'boolean','has_viewport_meta'=>'boolean']; }
    public function audit(): BelongsTo { return $this->belongsTo(SeoAudit::class, 'seo_audit_id'); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function links(): HasMany { return $this->hasMany(SeoLink::class); }
    public function scopeIndexable(Builder $query): Builder { return $query->where('is_indexable', true); }
    public function scopeOrphaned(Builder $query): Builder { return $query->where('inbound_internal_links', 0)->where('status_code', 200); }
}\n