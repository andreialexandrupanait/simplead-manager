<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * The part of an exception a person should be shown.
 *
 * Roughly forty places in the Livewire layer concatenated `$e->getMessage()` straight into a toast,
 * so what a site owner saw when a connector call failed was "cURL error 28: Operation timed out
 * after 30001 milliseconds with 0 bytes received". That is the truth and it is not an answer: it
 * does not say which of the two machines is at fault, and it does not say what to do.
 *
 * This does not try to be clever. It recognises the transport failures that make up almost all of
 * them, and otherwise hands back the original text trimmed to a length a toast can hold — because
 * a message we cannot improve still beats "Something went wrong", which throws away the only clue
 * anybody had.
 *
 * Full detail keeps going to the log. This is only what reaches the screen.
 */
final class HumanError
{
    public static function from(Throwable $e): string
    {
        return self::fromString($e->getMessage());
    }

    public static function fromString(?string $raw): string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return __('No reason was given.');
        }

        return match (true) {
            str_contains($raw, 'cURL error 28'),
            stripos($raw, 'timed out') !== false,
            stripos($raw, 'timeout') !== false => __('The site took too long to answer.'),

            str_contains($raw, 'cURL error 6'),
            str_contains($raw, 'cURL error 7'),
            stripos($raw, 'could not resolve host') !== false,
            stripos($raw, 'connection refused') !== false => __('The site could not be reached.'),

            str_contains($raw, 'cURL error 60'),
            stripos($raw, 'ssl') !== false && stripos($raw, 'certificate') !== false => __('The site\'s SSL certificate could not be verified.'),

            stripos($raw, 'INVALID_SIGNATURE') !== false,
            preg_match('/\b401\b/', $raw) === 1,
            stripos($raw, 'unauthorized') !== false => __('The site rejected this manager\'s credentials.'),

            preg_match('/\b403\b/', $raw) === 1,
            stripos($raw, 'forbidden') !== false => __('The site refused the request.'),

            preg_match('/\b404\b/', $raw) === 1 => __('The site does not offer that endpoint — its connector may be out of date.'),

            preg_match('/\b5\d\d\b/', $raw) === 1 => __('The site answered with an error of its own.'),

            default => \Illuminate\Support\Str::limit($raw, 160),
        };
    }
}
