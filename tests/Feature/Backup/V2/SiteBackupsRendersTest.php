<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteBackups;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

class SiteBackupsRendersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_screen_renders_with_a_schedule(): void
    {
        Redis::spy();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $site = Site::factory()->create(['user_id' => $admin->id]);
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => StorageDestination::factory()->create()->id,
            'is_enabled' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(SiteBackups::class, ['site' => $site])
            ->assertOk()
            ->assertSee('Back up now')
            ->assertSee('Schedule')
            ->assertSee('Storage');
    }

    public function test_the_screen_renders_without_one(): void
    {
        Redis::spy();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $site = Site::factory()->create(['user_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(SiteBackups::class, ['site' => $site])
            ->assertOk()
            ->assertSee('Nothing is scheduled');
    }
}
