<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\BackupStatus;
use PHPUnit\Framework\TestCase;

/**
 * Characterization test — pins the CURRENT backup/restore lifecycle contract before the
 * Snapshot-parity rebuild (see docs/backup/TARGET-ARCHITECTURE.md).
 *
 * BackupStatus is today reused for both `backups.status` and `backups.restore_status`. The
 * rebuild introduces a richer state machine (e.g. `object_missing`, `corrupt`, retry/paused).
 * This test locks the existing five-state contract so that any change is deliberate and
 * reviewed — it documents what we are preserving, not what we wish existed.
 */
class BackupStatusCharacterizationTest extends TestCase
{
    public function test_exactly_five_states_with_stable_string_values(): void
    {
        $values = array_map(static fn (BackupStatus $s): string => $s->value, BackupStatus::cases());

        // Stable wire/DB values — changing any of these is a breaking migration.
        self::assertSame(
            ['pending', 'in_progress', 'completed', 'failed', 'cancelled'],
            $values,
        );
    }

    public function test_from_persisted_value_round_trips(): void
    {
        self::assertSame(BackupStatus::Completed, BackupStatus::from('completed'));
        self::assertSame(BackupStatus::Failed, BackupStatus::from('failed'));
        self::assertNull(BackupStatus::tryFrom('object_missing'), 'new states are not part of the current contract yet');
    }

    /**
     * Every case must resolve label/color/icon without throwing. The enum uses exhaustive
     * match() with no default, so a newly-added case that forgets a branch fails here.
     */
    public function test_every_case_has_presentation_metadata(): void
    {
        foreach (BackupStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
            self::assertNotSame('', $status->color());
            self::assertNotSame('', $status->icon());
        }
    }

    public function test_current_presentation_mapping_is_pinned(): void
    {
        self::assertSame('Completed', BackupStatus::Completed->label());
        self::assertSame('In Progress', BackupStatus::InProgress->label());

        self::assertSame('green', BackupStatus::Completed->color());
        self::assertSame('red', BackupStatus::Failed->color());
        self::assertSame('gray', BackupStatus::Cancelled->color());

        self::assertSame('check-circle', BackupStatus::Completed->icon());
        self::assertSame('x-circle', BackupStatus::Failed->icon());
    }
}
