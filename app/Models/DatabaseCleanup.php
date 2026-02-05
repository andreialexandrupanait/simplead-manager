<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseCleanup extends Model
{
    protected $fillable = [
        'site_id',
        'revisions_deleted',
        'auto_drafts_deleted',
        'trash_posts_deleted',
        'spam_comments_deleted',
        'trash_comments_deleted',
        'transients_deleted',
        'orphaned_meta_deleted',
        'space_saved',
        'status',
        'error_message',
        'cleaned_at',
    ];

    protected $casts = [
        'revisions_deleted' => 'integer',
        'auto_drafts_deleted' => 'integer',
        'trash_posts_deleted' => 'integer',
        'spam_comments_deleted' => 'integer',
        'trash_comments_deleted' => 'integer',
        'transients_deleted' => 'integer',
        'orphaned_meta_deleted' => 'integer',
        'space_saved' => 'integer',
        'cleaned_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function getTotalDeletedAttribute(): int
    {
        return $this->revisions_deleted
            + $this->auto_drafts_deleted
            + $this->trash_posts_deleted
            + $this->spam_comments_deleted
            + $this->trash_comments_deleted
            + $this->transients_deleted
            + $this->orphaned_meta_deleted;
    }

    public function getFormattedSpaceSavedAttribute(): string
    {
        $bytes = $this->space_saved;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}
