<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Pindahkan user SUBLIM/KONVEKSI ke STAFF_GUDANG dulu, baru persempit enum
        DB::table('users')->whereIn('role', ['SUBLIM', 'KONVEKSI'])->update(['role' => 'STAFF_GUDANG']);

        // Hapus role lama, tambah role baru (Schema builder agar jalan di MySQL & SQLite)
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG', 'STAFF_GUDANG'])->default('STAFF_GUDANG')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
