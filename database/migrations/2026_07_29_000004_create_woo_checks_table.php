<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faza 5.2 — WooCommerce store-health monitoring (conditional).
 *
 * One row per site holding the latest signals returned by the connector's
 * /woo-health endpoint. Only sites that actually run WooCommerce (detected via the
 * existing SitePlugin inventory) are ever checked; a non-Woo site is stored with
 * applicable = false and never alerts.
 *
 * Expand-only + idempotent (guarded by hasTable) so a re-run at deploy is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('woo_checks')) {
            return;
        }

        Schema::create('woo_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Whether WooCommerce is active on the site at check time.
            $table->boolean('applicable')->default(false);

            // Manager-side GET on the checkout URL (HTTP status). Optional / nullable
            // — left NULL until the checkout probe is wired (see CheckWooHealth TODO).
            $table->unsignedSmallInteger('checkout_status')->nullable();

            // Derived: no gateway is enabled-but-unavailable AND at least one gateway
            // can take a payment.
            $table->boolean('gateways_ok')->default(true);

            $table->unsignedInteger('pending_orders_count')->default(0);
            $table->boolean('pending_over_threshold')->default(false);

            // WooCommerce 'woocommerce_scheduled_sales' cron is queued.
            $table->boolean('discount_cron_scheduled')->default(true);

            // Raw gateway list + any connector error, for drill-down.
            $table->jsonb('gateways')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_checks');
    }
};
