<?php

namespace Tests\Unit\Enums;

use App\Enums\BackupStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BackupStatusTest extends TestCase
{
    #[Test]
    public function it_has_expected_cases(): void
    {
        $cases = BackupStatus::cases();

        $this->assertContains(BackupStatus::Pending, $cases);
        $this->assertContains(BackupStatus::InProgress, $cases);
        $this->assertContains(BackupStatus::Completed, $cases);
        $this->assertContains(BackupStatus::Failed, $cases);
    }

    #[Test]
    public function it_has_correct_string_values(): void
    {
        $this->assertSame('pending', BackupStatus::Pending->value);
        $this->assertSame('in_progress', BackupStatus::InProgress->value);
        $this->assertSame('completed', BackupStatus::Completed->value);
        $this->assertSame('failed', BackupStatus::Failed->value);
    }

    #[Test]
    public function it_has_labels(): void
    {
        $this->assertNotEmpty(BackupStatus::Pending->label());
        $this->assertNotEmpty(BackupStatus::Completed->label());
    }

    #[Test]
    public function it_has_colors(): void
    {
        $this->assertNotEmpty(BackupStatus::Pending->color());
        $this->assertNotEmpty(BackupStatus::Completed->color());
        $this->assertNotEmpty(BackupStatus::Failed->color());
    }
}
