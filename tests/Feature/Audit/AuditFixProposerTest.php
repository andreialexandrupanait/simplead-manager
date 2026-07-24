<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditFixKind;
use App\Models\Audit;
use App\Models\AuditCard;
use App\Services\Audit\AuditFixProposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza D4 (skeleton): the Manager-side fix proposal + preview (no application).
 */
class AuditFixProposerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_infers_the_kind_from_the_title(): void
    {
        $this->assertSame(AuditFixKind::MetaTitle, AuditFixProposer::inferKind('Completează meta title lipsă'));
        $this->assertSame(AuditFixKind::MetaDescription, AuditFixProposer::inferKind('Adaugă meta descriere'));
        $this->assertSame(AuditFixKind::ImageAlt, AuditFixProposer::inferKind('Alt-text pentru imagini'));
        $this->assertSame(AuditFixKind::Redirect, AuditFixProposer::inferKind('Redirect 301 pentru URL-uri moarte'));
        $this->assertSame(AuditFixKind::Content, AuditFixProposer::inferKind('Îmbunătățește structura'));
    }

    public function test_it_proposes_changes_from_the_recommended_column(): void
    {
        $audit = Audit::factory()->create();
        $card = AuditCard::factory()->for($audit)->create([
            'title' => 'Completează meta title',
            'payload' => ['table' => ['rows' => [
                ['url' => 'https://x.ro/a', 'current' => 'scurt', 'recommended' => 'Un title bun | Brand'],
                ['url' => 'https://x.ro/b', 'current' => '', 'recommended' => ''], // no proposal → skipped
                ['url' => '', 'current' => 'x', 'recommended' => 'y'],             // no url → skipped
            ]]],
        ]);

        $fix = (new AuditFixProposer)->propose($card);

        $this->assertSame(AuditFixKind::MetaTitle, $fix['kind']);
        $this->assertCount(1, $fix['changes']);
        $this->assertSame(['url' => 'https://x.ro/a', 'current' => 'scurt', 'proposed' => 'Un title bun | Brand'], $fix['changes'][0]);
    }

    public function test_it_is_not_connector_applicable_yet(): void
    {
        $audit = Audit::factory()->create();
        $card = AuditCard::factory()->for($audit)->create([
            'title' => 'Redirect 301',
            'payload' => ['table' => ['rows' => [['url' => 'https://x.ro/old', 'current' => '404', 'recommended' => 'https://x.ro/new']]]],
        ]);

        $fix = (new AuditFixProposer)->propose($card);

        $this->assertSame(AuditFixKind::Redirect, $fix['kind']);
        $this->assertCount(1, $fix['changes']);
        $this->assertFalse($fix['applicable']); // skeleton — connector application not wired
    }

    public function test_a_card_without_a_table_has_no_changes(): void
    {
        $audit = Audit::factory()->create();
        $card = AuditCard::factory()->for($audit)->create(['title' => 'Ceva', 'payload' => null]);

        $fix = (new AuditFixProposer)->propose($card);

        $this->assertSame([], $fix['changes']);
        $this->assertFalse($fix['applicable']);
    }
}
