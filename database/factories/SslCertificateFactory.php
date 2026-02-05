<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SslCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;

class SslCertificateFactory extends Factory
{
    protected $model = SslCertificate::class;

    public function definition(): array
    {
        $daysRemaining = fake()->numberBetween(30, 365);

        return [
            'site_id' => Site::factory(),
            'domain' => fake()->domainName(),
            'issuer' => fake()->randomElement(["Let's Encrypt", 'DigiCert', 'Comodo', 'GeoTrust']),
            'issuer_organisation' => fake()->company(),
            'san_domains' => [fake()->domainName()],
            'signature_algorithm' => 'SHA256withRSA',
            'key_size' => fake()->randomElement([2048, 4096]),
            'protocol' => 'TLSv1.3',
            'cipher' => 'TLS_AES_256_GCM_SHA384',
            'issued_at' => now()->subDays($daysRemaining + 90),
            'expires_at' => now()->addDays($daysRemaining),
            'days_remaining' => $daysRemaining,
            'chain_valid' => true,
            'status' => 'valid',
            'alerts_enabled' => true,
            'warn_days' => 30,
            'last_checked_at' => now()->subHours(1),
        ];
    }
}
