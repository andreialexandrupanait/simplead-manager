<?php

namespace App\Models\Traits;

trait HasDomainExtraction
{
    private static array $twoPartTlds = [
        'co.uk', 'org.uk', 'me.uk', 'net.uk', 'ac.uk',
        'co.au', 'com.au', 'net.au', 'org.au',
        'co.nz', 'net.nz', 'org.nz',
        'co.za', 'org.za', 'web.za',
        'co.in', 'net.in', 'org.in',
        'com.br', 'net.br', 'org.br',
        'co.jp', 'or.jp', 'ne.jp',
        'co.kr', 'or.kr',
        'com.cn', 'net.cn', 'org.cn',
        'com.ro', 'org.ro', 'nom.ro',
        'co.il', 'org.il',
        'com.mx', 'org.mx',
        'com.ar', 'org.ar',
        'com.sg', 'org.sg',
        'com.hk', 'org.hk',
        'co.id', 'or.id',
        'com.my', 'org.my',
        'com.ph', 'org.ph',
        'com.tw', 'org.tw',
        'com.tr', 'org.tr',
        'co.th', 'or.th',
    ];

    public static function extractRootDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        // Remove www prefix
        $host = preg_replace('/^www\./', '', $host);

        $parts = explode('.', $host);

        if (count($parts) > 2) {
            $lastTwo = implode('.', array_slice($parts, -2));
            if (in_array($lastTwo, static::$twoPartTlds)) {
                $parts = array_slice($parts, -3);
            } else {
                $parts = array_slice($parts, -2);
            }
        }

        return implode('.', $parts);
    }
}
