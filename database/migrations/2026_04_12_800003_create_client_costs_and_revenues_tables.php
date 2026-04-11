<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // hosting, license, labor, other
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->boolean('is_recurring')->default(false);
            $table->string('recurring_interval')->nullable(); // monthly, yearly
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'type']);
        });

        Schema::create('client_revenues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // maintenance, project, other
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->boolean('is_recurring')->default(false);
            $table->string('recurring_interval')->nullable(); // monthly, yearly
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_revenues');
        Schema::dropIfExists('client_costs');
    }
};
