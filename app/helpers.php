<?php

declare(strict_types=1);

if (! function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        return app()->bound('csp-nonce') ? app('csp-nonce') : '';
    }
}
