<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Hapus role lama, tambah role baru
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('OWNER', 'SUPERVISOR', 'ADMIN_GUDANG', 'STAFF_GUDANG') NOT NULL");
        
        // Update user yang sebelumnya SUBLIM/KONVEKSI menjadi STAFF_GUDANG atau hapus (opsional)
        DB::table('users')->whereIn('role', ['SUBLIM', 'KONVEKSI'])->update(['role' => 'STAFF_GUDANG']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
