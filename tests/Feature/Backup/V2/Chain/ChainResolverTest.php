<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Chain;

use App\Backup\V2\Chain\BrokenChainException;
use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Backup\V2\Support\ArrayManifestReader;
use Tests\TestCase;

/**
 * ChainResolver: ordering, broken-chain detection, and final-state materialisation (full applied,
 * then each incremental — new/changed overwrite, tombstones delete). Pure DB + in-memory manifests
 * (no storage / plugin), so the chain algebra is proven deterministically.
 */
class ChainResolverTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ChainResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
        $this->resolver = new ChainResolver;
    }

    public function test_resolve_chain_of_full_is_just_the_full(): void
    {
        $full = $this->full();

        $chain = $this->resolver->resolveChain($full);

        $this->assertSame([$full->id], array_map(fn ($s) => $s->id, $chain));
    }

    public function test_resolve_chain_orders_full_then_increments(): void
    {
        $full = $this->full();
        $inc1 = $this->incremental($full, 1);
        $inc2 = $this->incremental($full, 2);

        $chain = $this->resolver->resolveChain($inc2);

        $this->assertSame([$full->id, $inc1->id, $inc2->id], array_map(fn ($s) => $s->id, $chain));
    }

    public function test_materialize_applies_tombstones_and_overwrites(): void
    {
        $full = $this->full();
        $inc1 = $this->incremental($full, 1);
        $inc2 = $this->incremental($full, 2);

        $reader = new ArrayManifestReader;
        // full: a=A0, b=B0, c=C0
        $reader->set($full->id, $this->manifest($full, [
            'a.txt' => 'A0', 'b.txt' => 'B0', 'c.txt' => 'C0',
        ]));
        // inc1: change a→A1, add d=D1, delete c
        $reader->set($inc1->id, $this->manifest($inc1, ['a.txt' => 'A1', 'd.txt' => 'D1'], ['c.txt']));
        // inc2: change b→B2, delete a
        $reader->set($inc2->id, $this->manifest($inc2, ['b.txt' => 'B2'], ['a.txt']));

        $state = $this->resolver->materialize($this->resolver->resolveChain($inc2), $reader);

        // Final: a deleted, b=B2, c deleted, d=D1.
        $this->assertSame(['b.txt', 'd.txt'], array_keys($state));
        $this->assertSame('B2', $state['b.txt']['sha256']);
        $this->assertSame($inc2->id, $state['b.txt']['source_session_id']);
        $this->assertSame('D1', $state['d.txt']['sha256']);
        $this->assertSame($inc1->id, $state['d.txt']['source_session_id']);
        $this->assertArrayNotHasKey('a.txt', $state);
        $this->assertArrayNotHasKey('c.txt', $state);
    }

    public function test_base_file_state_for_pending_incremental_is_prior_materialised_state(): void
    {
        $full = $this->full();
        $inc1 = $this->incremental($full, 1);
        $pending = $this->incremental($full, 2, state: BackupSessionState::Uploading);

        $reader = new ArrayManifestReader;
        $reader->set($full->id, $this->manifest($full, ['a.txt' => 'A0', 'b.txt' => 'B0']));
        $reader->set($inc1->id, $this->manifest($inc1, ['a.txt' => 'A1'], ['b.txt']));

        $base = $this->resolver->baseFileState($pending, $reader);

        // After full+inc1: a=A1 (b tombstoned). That is the base the pending inc2 diffs against.
        $this->assertCount(1, $base);
        $this->assertSame('a.txt', $base[0]['p']);
        $this->assertSame('A1', $base[0]['sha256']);
    }

    public function test_broken_chain_when_base_full_incomplete(): void
    {
        $full = $this->full(state: BackupSessionState::Failed);
        $inc = $this->incremental($full, 1);

        $this->expectException(BrokenChainException::class);
        $this->resolver->resolveChain($inc);
    }

    public function test_broken_chain_on_gap_in_positions(): void
    {
        $full = $this->full();
        $this->incremental($full, 1)->delete(); // position 1 removed → gap
        $inc2 = $this->incremental($full, 2);

        $this->expectException(BrokenChainException::class);
        $this->expectExceptionMessageMatches('/position 1/');
        $this->resolver->resolveChain($inc2);
    }

    public function test_broken_chain_when_manifest_missing_during_materialize(): void
    {
        $full = $this->full();
        $inc1 = $this->incremental($full, 1);

        $reader = new ArrayManifestReader;
        $reader->set($full->id, $this->manifest($full, ['a.txt' => 'A0']));
        // inc1 manifest deliberately NOT registered → missing.

        $this->expectException(BrokenChainException::class);
        $this->resolver->materialize($this->resolver->resolveChain($inc1), $reader);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function full(BackupSessionState $state = BackupSessionState::Completed): BackupSession
    {
        return $this->makeSession('full', $state, null, null);
    }

    private function incremental(BackupSession $base, int $position, BackupSessionState $state = BackupSessionState::Completed): BackupSession
    {
        return $this->makeSession('incremental', $state, $base->id, $position);
    }

    private function makeSession(string $type, BackupSessionState $state, ?int $baseId, ?int $position): BackupSession
    {
        return BackupSession::create([
            'site_id' => $this->site->id,
            'type' => $type,
            'scope' => ['files' => true, 'database' => false],
            'resource_profile' => 'low_impact',
            'state' => $state,
            'full_base_id' => $baseId,
            'chain_position' => $position,
            'idempotency_key' => 'chain-'.uniqid('', true),
            'format_version' => 'simplead-backup/1',
        ]);
    }

    /**
     * @param  array<string, string>  $included  path => sha256
     * @param  list<string>  $tombstones
     * @return array<string, mixed>
     */
    private function manifest(BackupSession $session, array $included, array $tombstones = []): array
    {
        $entries = [];
        $i = 0;
        foreach ($included as $p => $sha) {
            $entries[] = ['p' => $p, 's' => 1, 'm' => 1, 'sha256' => $sha, 'chunk_index' => $i++];
        }

        return [
            'backup_type' => $session->type,
            'full_base_id' => $session->full_base_id,
            'chain_position' => $session->chain_position,
            'objects' => array_map(
                static fn (array $e): array => ['kind' => 'files', 'chunk_index' => $e['chunk_index'], 'key' => "k/{$session->id}/{$e['chunk_index']}", 'size' => 1, 'sha256' => $e['sha256']],
                $entries,
            ),
            'files' => [
                'included' => $entries,
                'tombstones' => $tombstones,
            ],
        ];
    }
}
