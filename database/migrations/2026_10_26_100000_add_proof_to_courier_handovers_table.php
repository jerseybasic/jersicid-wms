<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bukti serah terima: tanda tangan kurir di layar dan foto tumpukan paket.
     */
    public function up(): void
    {
        Schema::table('courier_handovers', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('driver_name');
            $table->string('photo_path')->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('courier_handovers', function (Blueprint $table) {
            $table->dropColumn(['signature_path', 'photo_path']);
        });
    }
};
