<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_cleanups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revisions_deleted')->default(0);
            $table->unsignedInteger('auto_drafts_deleted')->default(0);
            $table->unsignedInteger('trash_posts_deleted')->default(0);
            $table->unsignedInteger('spam_comments_deleted')->default(0);
            $table->unsignedInteger('trash_comments_deleted')->default(0);
            $table->unsignedInteger('transients_deleted')->default(0);
            $table->unsignedInteger('orphaned_meta_deleted')->default(0);
            $table->unsignedBigInteger('space_saved')->default(0);
            $table->string('status')->default('completed');
            $table->text('error_message')->nullable();
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();

            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_cleanups');
    }
};
