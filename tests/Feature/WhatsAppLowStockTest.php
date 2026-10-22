<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\WhatsApp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Notifikasi stok menipis lewat WhatsApp (gateway Fonnte).
 */
class WhatsAppLowStockTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private const TOKEN = 'Ab12Cd34!Ef56Gh78+Ij';

    private function configure(): void
    {
        WhatsApp::saveToken(self::TOKEN);
        WhatsApp::saveTarget('081234567890');
    }

    public function test_owner_saves_whatsapp_settings_with_encrypted_token(): void
    {
        $owner = $this->userWithRole('OWNER');
        $this->actingAs($owner)->get(route('settings.index'))->assertOk()->assertSee('Notifikasi WhatsApp')->assertSee('Belum aktif');

        $this->put(route('settings.whatsapp'), ['whatsapp_token' => self::TOKEN, 'whatsapp_target' => '081234567890 , 6289876543210'])
            ->assertSessionHas('success');

        $this->assertNotSame(self::TOKEN, SystemSetting::get('whatsapp_token'));
        $this->assertSame(self::TOKEN, WhatsApp::token());
        $this->assertSame('081234567890,6289876543210', WhatsApp::target());
        $this->get(route('settings.index'))->assertSee('Aktif')->assertSee('Kirim Tes')->assertDontSee(self::TOKEN);

        // Token dikosongkan saat simpan ulang: token lama tetap
        $this->put(route('settings.whatsapp'), ['whatsapp_token' => '', 'whatsapp_target' => '120363025343298765@g.us'])->assertSessionHas('success');
        $this->assertSame(self::TOKEN, WhatsApp::token());
        $this->assertSame('120363025343298765@g.us', WhatsApp::target());

        $this->put(route('settings.whatsapp'), ['whatsapp_token' => 'abc', 'whatsapp_target' => 'nomor saya'])
            ->assertSessionHasErrors(['whatsapp_token', 'whatsapp_target']);

        $this->put(route('settings.whatsapp'), ['whatsapp_clear' => 1])->assertSessionHas('success');
        $this->assertFalse(WhatsApp::configured());

        $this->actingAs($this->userWithRole('SUPERVISOR'))
            ->put(route('settings.whatsapp'), ['whatsapp_target' => '081234567890'])->assertForbidden();
    }

    public function test_test_message_is_sent_through_fonnte(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $this->configure();
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true, 'detail' => 'success! message in queue'])]);

        $this->post(route('settings.whatsapp.test'))->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r->url() === WhatsApp::API_URL
            && $r->hasHeader('Authorization', self::TOKEN)
            && $r['target'] === '081234567890' && $r['countryCode'] === '62'
            && str_contains($r['message'], 'tidak ada stok menipis'));
    }

    public function test_whatsapp_errors_are_shown_instead_of_crashing(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $this->configure();
        Http::fake(['*' => Http::response(['status' => false, 'reason' => 'device disconnected'])]);

        $this->post(route('settings.whatsapp.test'))->assertSessionHasErrors('whatsapp');
        $this->assertStringContainsString('device disconnected', session('errors')->first('whatsapp'));
    }

    public function test_daily_command_sends_low_stock_list_only_when_needed(): void
    {
        Http::fake(['*' => Http::response(['status' => true])]);

        // Belum diatur: tidak menghubungi Fonnte
        $this->artisan('wms:kirim-stok-menipis')->assertSuccessful();
        Http::assertNothingSent();

        $this->configure();
        $this->makeSku('JSC-A-L')->update(['min_stock' => 5]);
        $this->putStock('JSC-A-L', 'A-01-01', 10);

        // Tidak ada yang menipis: tidak ada pesan
        $this->artisan('wms:kirim-stok-menipis')->expectsOutput('Tidak ada stok menipis.')->assertSuccessful();
        Http::assertNothingSent();

        $this->makeSku('JSC-B-L')->update(['min_stock' => 4]);
        $this->putStock('JSC-B-L', 'A-01-02', 2);

        $this->artisan('wms:kirim-stok-menipis')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r['message'], '*Stok menipis: 1 SKU*')
            && str_contains($r['message'], 'JSC-B-L CLUB TEST')
            && str_contains($r['message'], 'sisa *2* (batas 4)')
            && str_contains($r['message'], 'dari=stok-menipis')
            && !str_contains($r['message'], 'JSC-A-L'));
        Http::assertSentCount(1);

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command . ' @ ' . $e->expression);
        $this->assertTrue($events->contains(fn ($e) => str_contains($e, 'wms:kirim-stok-menipis') && str_ends_with($e, '0 8 * * *')));
    }
}
