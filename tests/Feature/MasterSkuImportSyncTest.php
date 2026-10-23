<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Import ulang Master SKU: pratinjau perubahan (baru / stok naik / turun / tetap) sebelum disimpan.
 */
class MasterSkuImportSyncTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private const HEADER = ['SKU', 'Artikel', 'Nama Produk', 'Versi', 'SUB_VERSION', 'Kategori Size', 'Size', 'Harga (Rp)', 'Min. Stok', 'Total Stok Saat Ini', 'Status', 'Dibuat Pada'];

    /**
     * File seperti sheet Google milik gudang: judul kolom bahasa Indonesia,
     * stok berupa rumus yang hanya menyimpan hasil terakhirnya.
     */
    private function sheet(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Worksheet');
        $sheet->fromArray(self::HEADER, null, 'A1');
        foreach ($rows as $i => $row) {
            $r = $i + 2;
            $sheet->fromArray(array_slice($row, 0, 9), null, "A{$r}");
            $cell = $sheet->getCell("J{$r}");
            if ($row[9] === null) {
                $cell->setValue(null);
            } else {
                $cell->setValueExplicit('=IFERROR(__xludf.DUMMYFUNCTION("IMPORTRANGE()"),' . $row[9] . ')', DataType::TYPE_FORMULA);
                $cell->setCalculatedValue($row[9]);
            }
            $sheet->setCellValue("K{$r}", $row[10] ?? 'AKTIF');
        }

        $path = tempnam(sys_get_temp_dir(), 'sku') . '.xlsx';
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        // Writer tidak menyimpan hasil rumus; tambahkan seperti file dari Google Sheets (<f>…</f><v>40</v>)
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = preg_replace('#(<f>[^<]*?,(\d+)\)</f>)#', '$1<v>$2</v>', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        return new UploadedFile($path, 'Master_Data_SKU.xlsx', null, null, true);
    }

    private function row(string $sku, ?int $stock, array $overrides = []): array
    {
        return array_replace([$sku, 'BASIC', 'JERSIC BASIC ' . $sku, 'BASIC', '-', 'DEWASA', 'M', 0, 10, $stock, 'AKTIF'], $overrides);
    }

    public function test_preview_shows_new_sku_and_stock_up_down_then_saves_with_movements(): void
    {
        $user = $this->userWithRole('OWNER');
        $this->actingAs($user);

        $this->makeSku('JER-UP-M');
        $this->putStock('JER-UP-M', 'A-01-01', 10);
        $this->makeSku('JER-DOWN-M');
        $this->putStock('JER-DOWN-M', 'DEFAULT-01', 5);
        $this->putStock('JER-DOWN-M', 'B-01-01', 15);
        $this->makeSku('JER-SAME-M');
        $this->putStock('JER-SAME-M', 'A-01-02', 4);
        $this->makeSku('JER-NOTINFILE-M');

        $file = $this->sheet([
            $this->row('JER-UP-M', 15),
            $this->row('JER-DOWN-M', 8),
            $this->row('JER-SAME-M', 4),
            $this->row('JER-NEW-L', 7, [6 => 'L']),
            $this->row('JER-NOCAT-M', 3, [5 => '-']),
            $this->row('JER-BADCAT-M', 3, [5 => 'ANAK']),
            $this->row('JER-UP-M', 99),
            $this->row('JER-KID-4', 2, [5 => 'KIDS', 6 => 4.0]),
        ]);

        $response = $this->post(route('master-skus.import'), ['file' => $file])->assertOk()
            ->assertSee('Stok bertambah')->assertSee('Stok berkurang')->assertSee('SKU baru');
        $summary = $response->viewData('summary');
        $this->assertSame(3, $summary['new']);
        $this->assertSame(12, $summary['new_qty']);
        $this->assertSame(1, $summary['up']);
        $this->assertSame(5, $summary['up_qty']);
        $this->assertSame(1, $summary['down']);
        $this->assertSame(12, $summary['down_qty']);
        $this->assertSame(2, $summary['skip']);
        $this->assertSame(1, $response->viewData('notInFile'));

        // Belum ada yang tersimpan sebelum dikonfirmasi
        $this->assertNull(MasterSku::where('sku', 'JER-NEW-L')->first());
        $this->assertSame(10, $this->stockQty('JER-UP-M', 'A-01-01'));

        $this->post(route('master-skus.import.store'), ['token' => $response->viewData('token')])
            ->assertRedirect(route('master-skus.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '3 SKU baru') && str_contains($m, '1 SKU stoknya bertambah') && str_contains($m, '1 SKU stoknya berkurang'));

        // Tambahan masuk ke lokasi yang sudah ada
        $this->assertSame(15, $this->stockQty('JER-UP-M', 'A-01-01'));
        // Pengurangan diambil dulu dari stok yang belum ditata di rak
        $this->assertSame(0, $this->stockQty('JER-DOWN-M', 'DEFAULT-01'));
        $this->assertSame(8, $this->stockQty('JER-DOWN-M', 'B-01-01'));
        $this->assertSame(4, $this->stockQty('JER-SAME-M', 'A-01-02'));
        // SKU baru masuk Master SKU dengan stok awal di DEFAULT-01
        $new = MasterSku::where('sku', 'JER-NEW-L')->first();
        $this->assertSame('JERSIC BASIC JER-NEW-L', $new->product_name);
        $this->assertSame('L', $new->size);
        $this->assertNull($new->sub_version);
        $this->assertSame(7, $this->stockQty('JER-NEW-L', 'DEFAULT-01'));
        $this->assertSame('4', MasterSku::where('sku', 'JER-KID-4')->value('size'));
        // Kategori kosong ("-") diisi DEWASA, kategori yang tidak dikenal dilewati
        $this->assertSame('DEWASA', MasterSku::where('sku', 'JER-NOCAT-M')->value('size_category'));
        $this->assertNull(MasterSku::where('sku', 'JER-BADCAT-M')->first());

        $movements = StockMovement::where('reference_type', 'IMPORT_SKU')->get();
        $this->assertCount(6, $movements);   // UP, DOWN di 2 lokasi, 3 SKU baru
        $this->assertTrue($movements->every(fn ($m) => $m->type === 'ADJUSTMENT' && $m->user_id === $user->id));
        $down = $movements->where('sku', 'JER-DOWN-M')->keyBy('bin_code');
        $this->assertSame([5, 0], [$down['DEFAULT-01']->qty_before, $down['DEFAULT-01']->qty_after]);
        $this->assertSame([15, 8], [$down['B-01-01']->qty_before, $down['B-01-01']->qty_after]);

        $audit = AuditTrail::where('module', 'MASTER_SKU')->where('action', 'IMPORT')->first();
        $this->assertSame(['before' => 10, 'after' => 15], $audit->new_values['stock_up']['JER-UP-M']);
        $this->assertSame(['before' => 20, 'after' => 8], $audit->new_values['stock_down']['JER-DOWN-M']);
        $this->assertEqualsCanonicalizing(['JER-NEW-L', 'JER-NOCAT-M', 'JER-KID-4'], $audit->new_values['new']);

        // Upload file yang sama lagi: tidak ada lagi yang berubah
        $this->post(route('master-skus.import'), ['file' => $file])->assertOk()->assertViewHas('hasWork', false);
    }

    public function test_blank_or_zero_values_do_not_wipe_saved_data(): void
    {
        $this->actingAs($this->userWithRole('SUPERVISOR'));
        $this->makeSku('JER-A-M', 150000)->update(['sub_version' => 'ONE PIECE']);
        $this->putStock('JER-A-M', 'A-01-01', 12);

        // Harga 0, kategori "-", stok kosong: semuanya dibiarkan; hanya nama yang berubah
        $response = $this->post(route('master-skus.import'), ['file' => $this->sheet([
            $this->row('JER-A-M', null, [1 => 'ART-1', 2 => 'NAMA BARU', 3 => 'V1', 4 => 'ONE PIECE', 5 => '-', 6 => 'L', 7 => 0]),
        ])])->assertOk()->assertSee('Kategori size');

        $row = $response->viewData('rows')[0];
        $this->assertSame(['product_name', 'min_stock'], array_keys($row['changes']));
        $this->assertNull($row['after']);

        $this->post(route('master-skus.import.store'), ['token' => $response->viewData('token')]);
        $sku = MasterSku::where('sku', 'JER-A-M')->first();
        $this->assertSame('NAMA BARU', $sku->product_name);
        $this->assertSame(150000, $sku->price);
        $this->assertSame('DEWASA', $sku->size_category);
        $this->assertSame(12, $this->stockQty('JER-A-M', 'A-01-01'));
        $this->assertSame(0, StockMovement::count());
    }

    public function test_stock_is_matched_to_file_even_if_it_moved_after_preview(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $this->makeSku('JER-A-M');
        $this->putStock('JER-A-M', 'A-01-01', 10);

        $token = $this->post(route('master-skus.import'), ['file' => $this->sheet([$this->row('JER-A-M', 6)])])->viewData('token');
        StockActive::where('sku', 'JER-A-M')->update(['qty' => 9]);   // ada picking di antara pratinjau dan simpan

        $this->post(route('master-skus.import.store'), ['token' => $token]);
        $this->assertSame(6, $this->stockQty('JER-A-M', 'A-01-01'));
        $this->assertSame(['qty_before' => 9, 'qty_after' => 6], StockMovement::first()->only(['qty_before', 'qty_after']));
    }

    public function test_unknown_file_and_expired_preview_are_rejected(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));

        $this->from(route('master-skus.index'))
            ->post(route('master-skus.import'), ['file' => UploadedFile::fake()->createWithContent('x.csv', "nama,qty\nA,1\n")])
            ->assertRedirect(route('master-skus.index'))->assertSessionHas('error');

        $this->post(route('master-skus.import.store'), ['token' => 'tidak-ada'])
            ->assertRedirect(route('master-skus.index'))->assertSessionHas('error');
    }

    public function test_only_owner_and_supervisor_can_import(): void
    {
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $this->post(route('master-skus.import'), ['file' => $this->sheet([$this->row('JER-A-M', 1)])])->assertForbidden();
    }
}
