<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\DailyShipmentReport;
use App\Services\WhatsApp;
use App\Services\WhatsAppSchedule;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Kiriman WhatsApp terjadwal berjalan sendiri pada jamnya, tanpa klik Kirim uji: dipicu agen WhatsApp
 * yang tersambung (walau cron belum dipasang) atau cron, dan tidak pernah terkirim ganda dalam sehari.
 */
class WhatsAppScheduleTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = WhatsApp::generateToken();
        DailyShipmentReport::save(true, '16:50', "081234567890\nGudang Jersic");
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function agentReports(string $state = 'connected')
    {
        Cache::forget('whatsapp-schedule-tick'); // tiap laporan agen dianggap datang setelah jeda 30 detik

        return $this->postJson(route('api.whatsapp.agent'), ['state' => $state], ['Authorization' => 'Bearer ' . $this->token])->assertOk();
    }

    private function reports(): int
    {
        return WhatsAppMessage::where('topic', DailyShipmentReport::TOPIC)->count();
    }

    public function test_connected_agent_sends_daily_report_at_the_set_time_without_cron(): void
    {
        Carbon::setTestNow('2026-11-04 16:49:00');
        $this->agentReports();
        $this->assertSame(0, $this->reports(), 'Belum jamnya');

        // Jam 16:50, agen belum tersambung (masih menunggu scan QR): belum dikirim
        Carbon::setTestNow('2026-11-04 16:50:10');
        $this->agentReports('qr');
        $this->assertSame(0, $this->reports());

        // Agen tersambung: laporan langsung masuk antrean dan ikut terambil agen
        $this->agentReports();
        $this->assertSame(2, $this->reports());
        $this->getJson(route('api.whatsapp.queue'), ['Authorization' => 'Bearer ' . $this->token])
            ->assertOk()->assertJsonCount(2, 'messages');

        // Laporan agen berikutnya dan pengambilan antrean hari ini tidak mengirim ulang
        Carbon::setTestNow('2026-11-04 17:30:00');
        $this->agentReports();
        Cache::forget('whatsapp-schedule-tick');
        $this->getJson(route('api.whatsapp.queue'), ['Authorization' => 'Bearer ' . $this->token])->assertOk();
        $this->assertSame(2, $this->reports());

        $log = WhatsAppSchedule::log()['daily_report'];
        $this->assertSame(['2026-11-04', 'agen', 'Masuk antrean untuk 2 penerima'], [$log['date'], $log['trigger'], $log['result']]);

        // Besok terkirim lagi
        Carbon::setTestNow('2026-11-05 16:51:00');
        $this->agentReports();
        $this->assertSame(4, $this->reports());
    }

    public function test_cron_and_agent_together_never_send_twice(): void
    {
        Carbon::setTestNow('2026-11-04 16:50:00');
        $this->artisan('wms:jadwal-whatsapp')->expectsOutput('Laporan kiriman harian: Masuk antrean untuk 2 penerima')->assertSuccessful();
        $this->agentReports();
        $this->artisan('wms:jadwal-whatsapp')->assertSuccessful();
        $this->assertSame(2, $this->reports());

        // Perintah lama dari versi sebelumnya juga tahu laporan sudah terkirim
        $this->artisan('wms:kirim-laporan-kiriman')->assertSuccessful();
        $this->assertSame(2, $this->reports());
        $this->assertNotNull(WhatsAppSchedule::cronSeenAt());

        // Sedang dikunci pemicu lain: pemicu kedua tidak menjalankan apa-apa
        Carbon::setTestNow('2026-11-05 16:50:00');
        $lock = Cache::lock('whatsapp-schedule', 120);
        $lock->get();
        $this->assertSame([], WhatsAppSchedule::runDue('agen'));
        $lock->release();
        $this->assertSame(['daily_report'], array_keys(WhatsAppSchedule::runDue('agen')));
        $this->assertSame(4, $this->reports());
    }

    public function test_low_stock_and_ship_reminder_run_once_a_day_through_the_same_schedule(): void
    {
        User::factory()->role('OWNER')->create(['whatsapp' => '081200001111', 'notify_whatsapp' => true, 'status' => 'AKTIF']);
        $this->makeSku('JSC-B-L')->update(['min_stock' => 4]);
        $this->putStock('JSC-B-L', 'A-01-02', 2);

        Carbon::setTestNow('2026-11-04 07:59:00');
        $this->assertSame([], WhatsAppSchedule::runDue('agen'));

        Carbon::setTestNow('2026-11-04 08:00:00');
        $this->assertSame(['low_stock' => 'Masuk antrean untuk 1 penerima'], WhatsAppSchedule::runDue('agen'));
        $this->assertSame([], WhatsAppSchedule::runDue('cron'));
        $this->assertSame(1, WhatsAppMessage::whereNull('topic')->where('body', 'like', '%Stok menipis%')->count());

        // Pengingat batas kirim (jam potong default 15 → 13:00): tidak ada order, tetap tercatat sudah jalan
        Carbon::setTestNow('2026-11-04 13:00:00');
        $this->assertSame(['ship_reminder' => 'Tidak ada order terlambat atau jatuh tempo hari ini'], WhatsAppSchedule::runDue('cron'));
        $this->assertSame([], WhatsAppSchedule::runDue('agen'));
    }

    public function test_schedule_is_registered_and_status_is_shown_in_settings(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'wms:jadwal-whatsapp'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);

        $this->actingAs($this->userWithRole('OWNER'));
        $this->get(route('settings.index'))->assertOk()
            ->assertSee('Jadwal otomatis WhatsApp')->assertSee('Belum pernah jalan otomatis')
            ->assertSee('Menunggu agen tersambung')->assertSee('Cron server belum pernah jalan');

        Carbon::setTestNow('2026-11-04 16:50:00');
        $this->agentReports();
        $this->get(route('settings.index'))->assertOk()
            ->assertSee('Dijalankan agen WhatsApp')
            ->assertSee('04/11 16:50 · Masuk antrean untuk 2 penerima')->assertSee('(dipicu agen)');

        $this->artisan('wms:jadwal-whatsapp');
        $this->get(route('settings.index'))->assertSee('Cron server aktif');
    }
}
