<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor auth (C-02): secret generation, QR provisioning, code
 * verification, and single-use recovery codes. Framework-agnostic core
 * (pragmarx/google2fa) wrapped with the app's enrollment lifecycle. Secrets
 * and recovery codes are encrypted at rest via the User model casts.
 */
class TwoFactorAuthService
{
    private const RECOVERY_CODE_COUNT = 8;

    private const CLOCK_TOLERANCE_WINDOWS = 1; // ±30s to absorb device clock drift

    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => sprintf('%s-%s', $this->randomCode(), $this->randomCode()))
            ->all();
    }

    /**
     * Provisioning URI (otpauth://) for the given secret, tied to the app + user.
     */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            (string) config('app.name'),
            $user->email,
            $secret,
        );
    }

    /**
     * Inline SVG QR code (data URI) for the provisioning URI — no external
     * requests, safe under the app's strict CSP.
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(192, 1),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($this->provisioningUri($user, $secret));
    }

    /**
     * Verify a 6-digit TOTP code against the user's secret.
     */
    public function verifyCode(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        return $this->engine->verifyKey($secret, $this->normalize($code), self::CLOCK_TOLERANCE_WINDOWS);
    }

    /**
     * Verify and CONSUME a recovery code (single use). Returns true and removes
     * the code on success; false otherwise.
     */
    public function verifyAndConsumeRecoveryCode(User $user, string $code): bool
    {
        $code = trim($code);
        $codes = $user->two_factor_recovery_codes ?? [];

        $remaining = array_values(array_filter(
            $codes,
            fn (string $stored) => ! hash_equals($stored, $code),
        ));

        if (count($remaining) === count($codes)) {
            return false; // no match consumed
        }

        $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();

        return true;
    }

    private function normalize(string $code): string
    {
        return str_replace(' ', '', trim($code));
    }

    private function randomCode(): string
    {
        // Ambiguity-free alphabet (no O/0/I/1) for codes users may read aloud.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 5; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
