<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Order yang belum diproses saat kiriman ditutup dan dikembalikan ke Pending
        Schema::table('shipment_closings', function (Blueprint $table) {
            $table->unsignedInteger('pending_count')->default(0)->after('left_count');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_closings', function (Blueprint $table) {
            $table->dropColumn('pending_count');
        });
    }
};
