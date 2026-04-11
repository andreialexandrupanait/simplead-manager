<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backlinks', function (Blueprint $table) {
            $table->text('page_title')->nullable()->after('source_type');
            $table->text('context_text')->nullable()->after('page_title');
            $table->integer('outbound_links_count')->nullable()->after('context_text');
            $table->string('link_position', 30)->nullable()->after('outbound_links_count');
            $table->string('anchor_type', 30)->nullable()->after('link_position');
            $table->timestamp('last_verified_at')->nullable()->after('anchor_type');
            $table->boolean('is_alive')->default(true)->after('last_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('backlinks', function (Blueprint $table) {
            $table->dropColumn(['page_title', 'context_text', 'outbound_links_count', 'link_position', 'anchor_type', 'last_verified_at', 'is_alive']);
        });
    }
};
