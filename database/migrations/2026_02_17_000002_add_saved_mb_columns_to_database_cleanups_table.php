<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_cleanups', function (Blueprint $table) {
            $table->unsignedBigInteger('revisions_saved')->default(0)->after('orphaned_meta_deleted');
            $table->unsignedBigInteger('auto_drafts_saved')->default(0)->after('revisions_saved');
            $table->unsignedBigInteger('trash_posts_saved')->default(0)->after('auto_drafts_saved');
            $table->unsignedBigInteger('spam_comments_saved')->default(0)->after('trash_posts_saved');
            $table->unsignedBigInteger('trash_comments_saved')->default(0)->after('spam_comments_saved');
            $table->unsignedBigInteger('transients_saved')->default(0)->after('trash_comments_saved');
            $table->unsignedBigInteger('orphaned_saved')->default(0)->after('transients_saved');
        });
    }

    public function down(): void
    {
        Schema::table('database_cleanups', function (Blueprint $table) {
            $table->dropColumn([
                'revisions_saved',
                'auto_drafts_saved',
                'trash_posts_saved',
                'spam_comments_saved',
                'trash_comments_saved',
                'transients_saved',
                'orphaned_saved',
            ]);
        });
    }
};
