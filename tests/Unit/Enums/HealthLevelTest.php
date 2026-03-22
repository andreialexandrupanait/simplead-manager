<?php

namespace Tests\Unit\Enums;

use App\Enums\HealthLevel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HealthLevelTest extends TestCase
{
    #[Test]
    public function it_returns_healthy_for_high_scores(): void
    {
        $this->assertSame(HealthLevel::Healthy, HealthLevel::fromScore(100));
        $this->assertSame(HealthLevel::Healthy, HealthLevel::fromScore(75));
    }

    #[Test]
    public function it_returns_warning_for_mid_scores(): void
    {
        $this->assertSame(HealthLevel::Warning, HealthLevel::fromScore(74));
        $this->assertSame(HealthLevel::Warning, HealthLevel::fromScore(50));
    }

    #[Test]
    public function it_returns_critical_for_low_scores(): void
    {
        $this->assertSame(HealthLevel::Critical, HealthLevel::fromScore(49));
        $this->assertSame(HealthLevel::Critical, HealthLevel::fromScore(0));
    }

    #[Test]
    public function it_returns_critical_when_site_is_down(): void
    {
        $this->assertSame(HealthLevel::Critical, HealthLevel::fromScore(100, isUp: false));
        $this->assertSame(HealthLevel::Critical, HealthLevel::fromScore(75, isUp: false));
    }

    #[Test]
    public function it_returns_unknown_for_null_score(): void
    {
        $this->assertSame(HealthLevel::Unknown, HealthLevel::fromScore(null));
    }

    #[Test]
    public function it_has_bg_color_for_each_level(): void
    {
        $this->assertNotEmpty(HealthLevel::Healthy->bgColor());
        $this->assertNotEmpty(HealthLevel::Warning->bgColor());
        $this->assertNotEmpty(HealthLevel::Critical->bgColor());
        $this->assertNotEmpty(HealthLevel::Unknown->bgColor());
    }

    #[Test]
    public function threshold_constants_are_correct(): void
    {
        $this->assertSame(75, HealthLevel::HEALTHY_THRESHOLD);
        $this->assertSame(50, HealthLevel::WARNING_THRESHOLD);
    }
}
