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
            $table->smallInteger('spam_score')->nullable()->after('source_type');
            $table->string('link_type', 30)->nullable()->after('is_nofollow');
        });
    }

    public function down(): void
    {
        Schema::table('backlinks', function (Blueprint $table) {
            $table->dropColumn(['spam_score', 'link_type']);
        });
    }
};
