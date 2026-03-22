<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SslCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SslCertificate>
 */
class SslCertificateFactory extends Factory
{
    protected $model = SslCertificate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issuedAt = fake()->dateTimeBetween('-6 months', '-1 month');
        $expiresAt = (clone $issuedAt)->modify('+1 year');
        $daysRemaining = now()->diffInDays($expiresAt, false);

        return [
            'site_id' => Site::factory(),
            'domain' => fake()->domainName(),
            'issuer' => fake()->randomElement(["Let's Encrypt", 'Sectigo', 'DigiCert', 'Comodo', 'GlobalSign']),
            'issuer_organisation' => fake()->randomElement(["Let's Encrypt", 'Sectigo Limited', 'DigiCert Inc', 'GlobalSign nv-sa']),
            'san_domains' => fn () => [fake()->domainName(), 'www.'.fake()->domainName()],
            'signature_algorithm' => 'SHA256withRSA',
            'key_size' => fake()->randomElement([2048, 4096]),
            'protocol' => 'TLSv1.3',
            'cipher' => 'TLS_AES_256_GCM_SHA384',
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'days_remaining' => max(0, (int) $daysRemaining),
            'chain_valid' => true,
            'status' => 'valid',
            'error_message' => null,
            'handshake_time' => fake()->numberBetween(50, 500),
            'alerts_enabled' => true,
            'warn_days' => 14,
            'last_alert_sent_at' => null,
            'last_checked_at' => fake()->dateTimeBetween('-1 hour', 'now'),
            'next_check_at' => fake()->dateTimeBetween('now', '+24 hours'),
        ];
    }

    /**
     * Indicate the certificate is valid.
     */
    public function valid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'valid',
            'chain_valid' => true,
            'days_remaining' => fake()->numberBetween(30, 365),
            'expires_at' => now()->addDays(fake()->numberBetween(30, 365)),
            'error_message' => null,
        ]);
    }

    /**
     * Indicate the certificate is expiring soon.
     */
    public function expiringSoon(): static
    {
        $daysRemaining = fake()->numberBetween(1, 14);

        return $this->state(fn (array $attributes) => [
            'status' => 'expiring_soon',
            'days_remaining' => $daysRemaining,
            'expires_at' => now()->addDays($daysRemaining),
        ]);
    }

    /**
     * Indicate the certificate has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'days_remaining' => 0,
            'expires_at' => fake()->dateTimeBetween('-30 days', '-1 day'),
        ]);
    }

    /**
     * Indicate there is an error with the certificate.
     */
    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'error',
            'chain_valid' => false,
            'error_message' => fake()->randomElement([
                'Certificate chain is incomplete',
                'Self-signed certificate detected',
                'Hostname mismatch',
                'Certificate revoked',
            ]),
        ]);
    }

    /**
     * Indicate the certificate check is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'issuer' => null,
            'issuer_organisation' => null,
            'issued_at' => null,
            'expires_at' => null,
            'days_remaining' => null,
            'chain_valid' => null,
            'last_checked_at' => null,
        ]);
    }
}
