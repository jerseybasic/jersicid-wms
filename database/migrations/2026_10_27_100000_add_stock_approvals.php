<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian stok perlu persetujuan Owner/Supervisor:
 * - opname yang diselesaikan staf berstatus SUBMITTED (menunggu persetujuan) sebelum stok disesuaikan;
 * - laporan "barang tidak ada di rak" saat picking disimpan dulu, stok rak baru dinolkan setelah disetujui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'SUBMITTED', 'COMPLETED', 'CANCELLED'])->default('DRAFT')->change();
            $table->foreignUuid('submitted_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('started_at');
            $table->text('rejection_note')->nullable()->after('notes');
        });

        Schema::create('missing_stock_reports', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 100);
            $table->string('bin_code', 50);
            $table->unsignedInteger('qty');
            $table->string('reference_no', 100)->nullable();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUuid('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missing_stock_reports');

        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['submitted_at', 'rejection_note']);
        });
    }
};
