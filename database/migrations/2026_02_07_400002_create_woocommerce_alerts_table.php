<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['low_stock', 'out_of_stock', 'failed_order', 'high_refunds']);
            $table->integer('product_id')->nullable();
            $table->string('product_name')->nullable();
            $table->text('message');
            $table->boolean('is_acknowledged')->default(false);
            $table->timestamps();

            $table->index(['site_id', 'is_acknowledged']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_alerts');
    }
};
