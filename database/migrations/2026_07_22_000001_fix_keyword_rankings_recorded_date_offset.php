<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * C-14: FetchKeywordRankings fetched the (final) GSC window of now()-3d but
 * stamped rows with the FETCH date, so the entire history sits shifted +3 days
 * from the dates the data actually describes. Shift it back once; the job now
 * labels rows with the data date. Rollback restores the old labeling.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('seo_keyword_rankings')->update([
            'recorded_date' => DB::raw("recorded_date - INTERVAL '3 days'"),
        ]);
    }

    public function down(): void
    {
        DB::table('seo_keyword_rankings')->update([
            'recorded_date' => DB::raw("recorded_date + INTERVAL '3 days'"),
        ]);
    }
};
