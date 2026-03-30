<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SiteUser;
use Illuminate\Support\Collection;

class SpamUserDetectionService
{
    private const SPAM_THRESHOLD = 5;

    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email',
        'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com', 'grr.la',
        'dispostable.com', 'maildrop.cc', 'temp-mail.org', 'fakeinbox.com',
        'trashmail.com', 'mailnesia.com', 'tempinbox.com', 'burnermail.io',
        '10minutemail.com', 'getnada.com', 'emailondeck.com', 'mohmal.com',
    ];

    /**
     * @return array{flagged: Collection, summary: array{total_scanned: int, flagged_count: int, highest_score: int}}
     */
    public function detect(int $siteId): array
    {
        $users = SiteUser::where('site_id', $siteId)->get();

        // Pre-compute bulk registration windows
        $registrationWindows = $this->findBulkRegistrationWindows($users);

        // Pre-compute Gmail dot-variant duplicates
        $gmailDotVariants = $this->findGmailDotVariants($users);

        $flagged = collect();

        foreach ($users as $user) {
            // Skip administrators
            if ($user->role === 'administrator') {
                continue;
            }

            $score = 0;
            $reasons = [];

            // Subscriber role (+1)
            if ($user->role === 'subscriber') {
                $score += 1;
                $reasons[] = 'Subscriber role';
            }

            // Never logged in (+2)
            if ($user->last_login_at === null) {
                $score += 2;
                $reasons[] = 'Never logged in';
            }

            // No orders (+1)
            if ($user->orders_count === 0) {
                $score += 1;
                $reasons[] = 'No orders';
            }

            // No posts (+1)
            if ($user->posts_count === 0) {
                $score += 1;
                $reasons[] = 'No posts';
            }

            // Gibberish username (+2)
            if ($this->isGibberishUsername($user->username)) {
                $score += 2;
                $reasons[] = 'Suspicious username';
            }

            // Suspicious email (+2)
            if ($this->isSuspiciousEmail($user->email)) {
                $score += 2;
                $reasons[] = 'Suspicious email';
            }

            // Bulk registration (+2)
            if (isset($registrationWindows[$user->id])) {
                $score += 2;
                $reasons[] = 'Bulk registration pattern';
            }

            // Gmail dot-variant duplicate (+3)
            if (isset($gmailDotVariants[$user->id])) {
                $score += 3;
                $reasons[] = 'Gmail dot-variant duplicate';
            }

            // Display name matches username (+1)
            if ($user->display_name !== null && $user->display_name === $user->username) {
                $score += 1;
                $reasons[] = 'Display name matches username';
            }

            if ($score >= self::SPAM_THRESHOLD) {
                $flagged->push([
                    'user_id' => $user->id,
                    'wp_user_id' => $user->wp_user_id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'score' => $score,
                    'reasons' => $reasons,
                ]);
            }
        }

        $flagged = $flagged->sortByDesc('score')->values();

        return [
            'flagged' => $flagged,
            'summary' => [
                'total_scanned' => $users->count(),
                'flagged_count' => $flagged->count(),
                'highest_score' => $flagged->max('score') ?? 0,
            ],
        ];
    }

    private function isGibberishUsername(string $username): bool
    {
        // Hex-like strings (8+ hex chars)
        if (preg_match('/^[0-9a-f]{8,}$/i', $username)) {
            return true;
        }

        // Number-heavy (more than 50% digits, at least 4 chars)
        if (strlen($username) >= 4) {
            $digitCount = preg_match_all('/\d/', $username);
            if ($digitCount / strlen($username) > 0.5) {
                return true;
            }
        }

        // Consonant clusters (4+ consecutive consonants, excluding common patterns)
        if (preg_match('/[bcdfghjklmnpqrstvwxyz]{5,}/i', $username)) {
            return true;
        }

        // Dot-separated spam pattern (e.g., a.l.e.x.j.a.r.b.o.us.mc)
        $dotCount = substr_count($username, '.');
        $alphaCount = preg_match_all('/[a-z]/i', $username);
        if ($dotCount >= 3 && $alphaCount > 0 && ($dotCount / $alphaCount) > 0.3) {
            return true;
        }

        return false;
    }

    private function isSuspiciousEmail(?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }

        [$local, $domain] = $parts;

        // Disposable email domain
        if (in_array(strtolower($domain), self::DISPOSABLE_DOMAINS, true)) {
            return true;
        }

        // Hex-like local part (8+ hex chars)
        if (preg_match('/^[0-9a-f]{8,}$/i', $local)) {
            return true;
        }

        // Dot-separated local part spam pattern
        $dotCount = substr_count($local, '.');
        $alphaCount = preg_match_all('/[a-z]/i', $local);
        if ($dotCount >= 3 && $alphaCount > 0 && ($dotCount / $alphaCount) > 0.3) {
            return true;
        }

        return false;
    }

    /**
     * Find users with Gmail dot-variant duplicates (same address when dots are stripped).
     *
     * @return array<int, true> Keyed by user ID
     */
    private function findGmailDotVariants(Collection $users): array
    {
        $gmailDomains = ['gmail.com', 'googlemail.com'];
        $groups = [];

        foreach ($users as $user) {
            if ($user->email === null || $user->email === '') {
                continue;
            }

            $parts = explode('@', strtolower($user->email));
            if (count($parts) !== 2 || ! in_array($parts[1], $gmailDomains, true)) {
                continue;
            }

            // Strip dots from local part to normalize
            $normalized = str_replace('.', '', $parts[0]) . '@' . $parts[1];
            $groups[$normalized][] = $user->id;
        }

        $flagged = [];
        foreach ($groups as $userIds) {
            if (count($userIds) >= 2) {
                foreach ($userIds as $id) {
                    $flagged[$id] = true;
                }
            }
        }

        return $flagged;
    }

    /**
     * Find users registered within 10-minute windows with 5+ others.
     *
     * @return array<int, true> Keyed by user ID
     */
    private function findBulkRegistrationWindows(Collection $users): array
    {
        $usersWithDates = $users
            ->filter(fn ($u) => $u->registered_at !== null)
            ->sortBy('registered_at')
            ->values();

        $flagged = [];

        foreach ($usersWithDates as $i => $user) {
            $windowEnd = $user->registered_at->copy()->addMinutes(10);
            $inWindow = 0;

            for ($j = $i + 1; $j < $usersWithDates->count(); $j++) {
                if ($usersWithDates[$j]->registered_at->lte($windowEnd)) {
                    $inWindow++;
                } else {
                    break;
                }
            }

            // 5+ other users in the same 10-minute window (total 6+ including this one)
            if ($inWindow >= 4) {
                $flagged[$user->id] = true;
                for ($j = $i + 1; $j < $usersWithDates->count() && $j <= $i + $inWindow; $j++) {
                    $flagged[$usersWithDates[$j]->id] = true;
                }
            }
        }

        return $flagged;
    }
}
