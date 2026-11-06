<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\Services\DailyBackup;
use App\Services\WhatsApp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Backup harian lewat cron: sekali berhasil per hari, menyusul bila jam 01:30 terlewat,
 * dicoba ulang bila gagal, dan memberi tahu lewat WhatsApp sekali.
 */
class DailyBackupTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function fakeBackup(int $exitCode): void
    {
        Artisan::shouldReceive('call')->with('backup:clean', \Mockery::any())->andReturn(0);
        Artisan::shouldReceive('call')->with('backup:run', \Mockery::any())->andReturn($exitCode);
        Artisan::shouldReceive('output')->andReturn("Starting backup...\nBackup failed because: The dump process failed.\nExitcode\n========\n127\n\n"
            . "Error Output\n============\nsh: 1: mysqldump: not found\n.\n#0 /vendor/spatie/db-dumper/src/DbDumper.php(227)\n");
    }

    public function test_runs_once_per_day_after_backup_time_and_catches_up_later(): void
    {
        $this->fakeBackup(0);

        $this->assertNull(DailyBackup::runDue('cron', Carbon::parse('2026-11-05 01:20')));
        // Jam 01:30 terlewat (server mati), backup menyusul jam 07:40
        $this->assertStringStartsWith('Berhasil', DailyBackup::runDue('cron', Carbon::parse('2026-11-05 07:40')));
        $this->assertNull(DailyBackup::runDue('cron', Carbon::parse('2026-11-05 07:50')));
        $this->assertStringStartsWith('Berhasil', DailyBackup::runDue('cron', Carbon::parse('2026-11-06 01:30')));
    }

    public function test_failure_is_retried_and_reported_once_on_whatsapp(): void
    {
        $member = $this->userWithRole('OWNER');
        $member->update(['whatsapp' => '081234567890', 'notify_whatsapp' => true]);
        WhatsApp::generateToken();
        $this->fakeBackup(1);

        $first = DailyBackup::runDue('cron', Carbon::parse('2026-11-05 01:30'));
        $this->assertSame('Gagal: The dump process failed. (sh: 1: mysqldump: not found)', $first);
        DailyBackup::runDue('cron', Carbon::parse('2026-11-05 01:40'));
        DailyBackup::runDue('cron', Carbon::parse('2026-11-05 01:50'));
        // Jatah percobaan hari ini habis
        $this->assertNull(DailyBackup::runDue('cron', Carbon::parse('2026-11-05 02:00')));

        $this->assertSame(1, WhatsAppMessage::where('body', 'like', '%Backup database gagal%')->count());
        $this->assertSame(3, DailyBackup::log()['attempts']);
    }

    public function test_settings_page_shows_cron_and_newest_backup(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(config('backup.backup.name') . '/2026-11-05-01-30-00.zip', str_repeat('x', 2048));

        $this->actingAs($this->userWithRole('OWNER'))->get(route('settings.index'))->assertOk()
            ->assertSee('Cron Server &amp; Backup', false)
            ->assertSee('Cron belum jalan')
            ->assertSee('php artisan schedule:run')
            ->assertSee('2 KB');

        $this->artisan('wms:jadwal-whatsapp')->assertSuccessful();
        $this->get(route('settings.index'))->assertSee('Cron aktif');
    }
}
