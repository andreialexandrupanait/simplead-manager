<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;

class GeneralSettingsFormData extends Form
{
    #[Validate('required|string|max:255')]
    public string $appName = 'SimpleAd Manager';

    #[Validate('nullable|url|max:255')]
    public string $appUrl = '';

    #[Validate('required|timezone')]
    public string $defaultTimezone = 'UTC';

    #[Validate('required|string|max:50')]
    public string $dateFormat = 'M d, Y';

    #[Validate('required|integer|min:60|max:3600')]
    public int $defaultInterval = 300;

    #[Validate('required|integer|min:5|max:120')]
    public int $defaultTimeout = 30;

    #[Validate('required|integer|min:1|max:10')]
    public int $alertAfterFailures = 3;

    #[Validate('required|integer|min:10|max:200')]
    public int $dashboardPerPage = 30;

    #[Validate('nullable|string|regex:/^#[0-9a-fA-F]{6}$/')]
    public ?string $accentColor = null;

    #[Validate('nullable|file|mimes:jpeg,jpg,png,gif,webp,ico,svg|max:1024')]
    public $favicon;

    #[Validate('nullable|file|mimes:jpeg,jpg,png,gif,webp,svg|max:2048')]
    public $logo;
}
