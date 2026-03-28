<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event')->index();
            $table->string('title_template');
            $table->text('message_template');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
