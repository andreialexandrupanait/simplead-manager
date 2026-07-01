<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\DeleteSpamUsersJob;
use App\Models\Site;
use App\Models\SiteUser;
use App\Services\JobTracker;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class DeleteSpamUsersJobTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        // JobTracker writes activity logs to Redis; keep the test hermetic.
        Redis::spy();
        $this->site = Site::factory()->create();
    }

    private function makeSiteUser(int $wpUserId, string $username): SiteUser
    {
        return SiteUser::create([
            'site_id' => $this->site->id,
            'wp_user_id' => $wpUserId,
            'username' => $username,
            'email' => "{$username}@example.com",
            'display_name' => $username,
            'role' => 'subscriber',
            'synced_at' => now(),
        ]);
    }

    public function test_deletes_local_records_for_users_deleted_on_wordpress(): void
    {
        $spam = $this->makeSiteUser(101, 'spammer');
        $keep = $this->makeSiteUser(202, 'legit');

        $api = $this->createMockApi();
        // The connector returns deleted/failed at the top level (no `data` wrapper).
        $api->expects($this->once())
            ->method('bulkDeleteUsers')
            ->with([101], null)
            ->willReturn([
                'success' => true,
                'deleted' => [['id' => 101, 'username' => 'spammer']],
                'failed' => [],
            ]);

        $this->app->instance(
            WordPressApiServiceFactory::class,
            $this->createMockApiFactory($api),
        );

        (new DeleteSpamUsersJob($this->site, [101]))->handle();

        $this->assertDatabaseMissing('site_users', ['id' => $spam->id]);
        $this->assertDatabaseHas('site_users', ['id' => $keep->id]);

        $status = JobTracker::get('spam-delete-'.$this->site->id);
        $this->assertSame('complete', $status['status']);
        $this->assertSame('Deleted 1 of 1 spam users', $status['message']);
    }

    public function test_keeps_local_record_when_wordpress_reports_failure(): void
    {
        $stuck = $this->makeSiteUser(303, 'lastadmin');

        $api = $this->createMockApi();
        $api->method('bulkDeleteUsers')->willReturn([
            'success' => true,
            'deleted' => [],
            'failed' => [['id' => 303, 'reason' => 'Cannot delete the last administrator']],
        ]);

        $this->app->instance(
            WordPressApiServiceFactory::class,
            $this->createMockApiFactory($api),
        );

        (new DeleteSpamUsersJob($this->site, [303]))->handle();

        $this->assertDatabaseHas('site_users', ['id' => $stuck->id]);

        $status = JobTracker::get('spam-delete-'.$this->site->id);
        $this->assertSame('complete', $status['status']);
        $this->assertSame('Deleted 0 of 1 spam users (1 failed)', $status['message']);
    }
}
