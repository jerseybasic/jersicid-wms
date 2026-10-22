<?php

namespace App\Console\Commands;

use App\Services\LowStock;
use App\Services\Telegram;
use Illuminate\Console\Command;

/**
 * Ringkasan stok menipis ke Telegram. Dijadwalkan tiap pagi; tidak mengirim apa-apa jika tidak ada yang menipis.
 */
class SendLowStockAlert extends Command
{
    protected $signature = 'wms:kirim-stok-menipis';

    protected $description = 'Kirim daftar SKU yang stoknya menipis ke Telegram';

    private const MAX_LINES = 30;

    public function handle(): int
    {
        if (!Telegram::configured()) {
            $this->info('Telegram belum diatur, tidak ada yang dikirim.');

            return self::SUCCESS;
        }

        $message = self::message();
        if ($message === null) {
            $this->info('Tidak ada stok menipis.');

            return self::SUCCESS;
        }

        if ($error = Telegram::send($message)) {
            $this->error($error);

            return self::FAILURE;
        }

        $this->info('Ringkasan stok menipis terkirim.');

        return self::SUCCESS;
    }

    /** Isi pesan, atau null jika tidak ada SKU yang menipis. */
    public static function message(): ?string
    {
        $total = LowStock::count();
        if ($total === 0) {
            return null;
        }

        $items = LowStock::query()->orderBy('current_stock')->orderBy('master_skus.sku')->limit(self::MAX_LINES)->get();
        $e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES);

        $lines = ["<b>Stok menipis: {$total} SKU</b> (" . now()->format('d/m/Y') . ')', ''];
        foreach ($items as $sku) {
            $lines[] = "• <code>{$e($sku->sku)}</code> {$e(trim($sku->product_name . ' ' . $sku->size))}: sisa <b>" . (int) $sku->current_stock . '</b> (batas ' . (int) $sku->limit_qty . ')';
        }
        if ($total > self::MAX_LINES) {
            $lines[] = '…dan ' . ($total - self::MAX_LINES) . ' SKU lainnya.';
        }
        $lines[] = '';
        $lines[] = 'Buat PO: ' . route('po.create', ['dari' => 'stok-menipis']);

        return implode("\n", $lines);
    }
}
