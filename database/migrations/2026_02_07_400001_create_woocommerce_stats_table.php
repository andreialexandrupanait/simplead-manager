<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->integer('orders_count')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->decimal('average_order_value', 10, 2)->default(0);
            $table->integer('products_sold_count')->default(0);
            $table->integer('refunds_count')->default(0);
            $table->decimal('refunds_amount', 10, 2)->default(0);
            $table->integer('new_customers')->default(0);
            $table->integer('returning_customers')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_stats');
    }
};
