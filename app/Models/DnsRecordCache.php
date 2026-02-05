<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsRecordCache extends Model
{
    protected $table = 'dns_records_cache';

    protected $fillable = [
        'site_id',
        'domain',
        'a_records',
        'aaaa_records',
        'cname_records',
        'mx_records',
        'txt_records',
        'ns_records',
        'soa_record',
        'has_www',
        'uses_cloudflare',
        'has_spf',
        'has_dmarc',
        'has_dkim',
        'mail_provider',
        'email_security_score',
        'total_records',
        'checked_at',
    ];

    protected $casts = [
        'a_records' => 'array',
        'aaaa_records' => 'array',
        'cname_records' => 'array',
        'mx_records' => 'array',
        'txt_records' => 'array',
        'ns_records' => 'array',
        'soa_record' => 'array',
        'has_www' => 'boolean',
        'uses_cloudflare' => 'boolean',
        'has_spf' => 'boolean',
        'has_dmarc' => 'boolean',
        'has_dkim' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
