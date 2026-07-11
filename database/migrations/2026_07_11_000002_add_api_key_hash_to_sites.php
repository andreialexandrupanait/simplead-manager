<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit SC-A2-03: agent auth looked up sites by the `encrypted`-cast api_key
 * column, which can never match a plaintext token (random IV per encryption),
 * so every agent call 401'd. Add a deterministic sha256 lookup column; the
 * encrypted api_key stays for at-rest secrecy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('api_key_hash', 64)->nullable()->index();
        });

        // Backfill from the encrypted api_key values.
        DB::table('sites')->whereNotNull('api_key')->orderBy('id')
            ->chunkById(100, function ($sites) {
                foreach ($sites as $site) {
                    try {
                        $plain = Crypt::decryptString($site->api_key);
                    } catch (\Throwable) {
                        continue; // undecryptable legacy value — leave hash NULL
                    }

                    DB::table('sites')->where('id', $site->id)
                        ->update(['api_key_hash' => hash('sha256', $plain)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('api_key_hash');
        });
    }
};
