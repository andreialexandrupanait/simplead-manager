<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('website')->nullable()->after('company');
            $table->string('address')->nullable()->after('website');
            $table->string('city')->nullable()->after('address');
            $table->string('country')->nullable()->after('city');
            $table->string('vat_number')->nullable()->after('country');
            $table->string('registration_number')->nullable()->after('vat_number');
            $table->string('status')->default('active')->after('is_active');
            $table->softDeletes();

            $table->index('status');
            $table->index('company');
        });

        // Migrate is_active to status
        DB::table('clients')->where('is_active', true)->update(['status' => 'active']);
        DB::table('clients')->where('is_active', false)->update(['status' => 'inactive']);

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('notes');
        });

        // Migrate status back to is_active
        DB::table('clients')->where('status', 'active')->update(['is_active' => true]);
        DB::table('clients')->whereIn('status', ['inactive', 'archived'])->update(['is_active' => false]);

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'website',
                'address',
                'city',
                'country',
                'vat_number',
                'registration_number',
                'status',
            ]);
            $table->dropSoftDeletes();
            $table->dropIndex(['status']);
            $table->dropIndex(['company']);
        });
    }
};
