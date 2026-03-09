<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('sections');
            $table->string('company_name')->nullable();
            $table->string('company_logo_path')->nullable();
            $table->string('company_website')->nullable();
            $table->string('primary_color', 7)->default('#7C3AED');
            $table->text('intro_text')->nullable();
            $table->text('closing_text')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};
