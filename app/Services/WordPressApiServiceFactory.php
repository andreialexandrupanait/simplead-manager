<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WordPressApiServiceInterface;
use App\Models\Site;

class WordPressApiServiceFactory
{
    public function make(Site $site): WordPressApiServiceInterface
    {
        return new WordPressApiService($site);
    }
}
