<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\WhatsApp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Notifikasi stok menipis lewat WhatsApp ke anggota yang diatur di Kelola User.
 */
class WhatsAppLowStockTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private const SENDER = 'http://127.0.0.1:3100';
    private const KEY = 'kunci-rahasia-panjang-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_sender.url' => self::SENDER, 'services.whatsapp_sender.key' => self::KEY]);
    }

    private function member(string $name, ?string $number, bool $notify = true, string $status = 'AKTIF'): User
    {
        return User::factory()->role('STAFF_GUDANG')->create(['name' => $name, 'whatsapp' => $number, 'notify_whatsapp' => $notify, 'status' => $status]);
    }

    private function userForm(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Rina Gudang', 'email' => 'rina@test.id', 'password' => 'passwordpanjang1', 'password_confirmation' => 'passwordpanjang1',
            'role' => 'ADMIN_GUDANG', 'workspace_default' => 'GUDANG', 'status' => 'AKTIF',
        ];
    }

    public function test_members_get_whatsapp_number_and_notification_choice_in_user_management(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));

        $this->post(route('users.store'), $this->userForm(['whatsapp' => '0812-3456-7890', 'notify_whatsapp' => 1]))->assertSessionHasNoErrors();
        $rina = User::where('email', 'rina@test.id')->first();
        $this->assertSame('ADMIN_GUDANG', $rina->role);
        $this->assertTrue($rina->notify_whatsapp);

        $this->post(route('users.store'), $this->userForm(['email' => 'x@test.id', 'whatsapp' => '12345']))->assertSessionHasErrors('whatsapp');

        // Centang notifikasi tanpa nomor tidak berlaku
        $this->put(route('users.update', $rina->id), ['whatsapp' => '', 'notify_whatsapp' => 1] + $this->userForm())->assertSessionHasNoErrors();
        $this->assertFalse($rina->fresh()->notify_whatsapp);

        $this->get(route('users.edit', $rina->id))->assertSee('No. WhatsApp')->assertSee('Admin Gudang');
    }

    public function test_recipients_are_active_members_plus_extra_numbers(): void
    {
        $this->member('Andi', '081200001111');
        $this->member('Budi', '+62 812 0000 2222');
        $this->member('Citra', '081200003333', false);           // tidak dicentang
        $this->member('Dodi', '081200004444', true, 'NONAKTIF'); // nonaktif
        WhatsApp::saveExtraTargets('081200001111, 089900005555'); // duplikat dibuang

        $this->assertSame(['6281200001111', '6281200002222', '6289900005555'], WhatsApp::recipients());
        $this->assertSame(['Andi', 'Budi'], WhatsApp::members()->pluck('name')->all());
    }

    public function test_owner_chooses_sender_and_sees_recipients(): void
    {
        $this->member('Andi Picker', '081200001111');
        $this->actingAs($this->userWithRole('OWNER'));

        $this->get(route('settings.index'))->assertOk()->assertSee('Notifikasi WhatsApp')->assertSee('Penerima: 1 anggota')
            ->assertSee('Andi Picker')->assertSee('Server sendiri (WhatsApp Web)')->assertSee('Aktif');

        $this->put(route('settings.whatsapp'), ['whatsapp_driver' => 'fonnte', 'whatsapp_token' => 'Ab12Cd34!Ef56Gh78+Ij', 'whatsapp_target' => '089900005555'])
            ->assertSessionHas('success');
        $this->assertSame('fonnte', WhatsApp::driver());
        $this->assertNotSame('Ab12Cd34!Ef56Gh78+Ij', SystemSetting::get('whatsapp_token'));
        $this->assertSame('Ab12Cd34!Ef56Gh78+Ij', WhatsApp::token());
        $this->get(route('settings.index'))->assertDontSee('Ab12Cd34!Ef56Gh78+Ij');

        $this->put(route('settings.whatsapp'), ['whatsapp_driver' => 'lain', 'whatsapp_target' => 'nomor saya'])
            ->assertSessionHasErrors(['whatsapp_driver', 'whatsapp_target']);

        $this->actingAs($this->userWithRole('SUPERVISOR'))
            ->put(route('settings.whatsapp'), ['whatsapp_driver' => 'server'])->assertForbidden();
        $this->get(route('settings.whatsapp.qr'))->assertForbidden();
    }

    public function test_own_sender_sends_to_members_and_reports_failures(): void
    {
        $this->member('Andi', '081200001111');
        $this->member('Budi', '081200002222');
        $this->actingAs($this->userWithRole('OWNER'));

        Http::fake([self::SENDER . '/send' => Http::sequence()
            ->push(['ok' => true, 'results' => [['to' => '6281200001111', 'ok' => true], ['to' => '6281200002222', 'ok' => true]]])
            ->push(['ok' => true, 'results' => [['to' => '6281200001111', 'ok' => true], ['to' => '6281200002222', 'ok' => false, 'error' => 'nomor tidak terdaftar di WhatsApp']]])
            ->push(['ok' => false, 'error' => 'WhatsApp belum login. Scan QR di halaman Pengaturan WMS.'], 503)]);

        $this->post(route('settings.whatsapp.test'))->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r->url() === self::SENDER . '/send'
            && $r->hasHeader('X-Api-Key', self::KEY)
            && $r['to'] === ['6281200001111', '6281200002222']
            && str_contains($r['message'], 'tidak ada stok menipis'));

        $this->post(route('settings.whatsapp.test'))->assertSessionHasErrors('whatsapp');
        $this->assertStringContainsString('6281200002222 (nomor tidak terdaftar', session('errors')->first('whatsapp'));

        $this->post(route('settings.whatsapp.test'))->assertSessionHasErrors('whatsapp');
        $this->assertStringContainsString('belum login', session('errors')->first('whatsapp'));
    }

    public function test_status_qr_and_logout_go_through_the_sender(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        Http::fake([
            self::SENDER . '/status' => Http::response(['ok' => true, 'state' => 'QR', 'me' => null]),
            self::SENDER . '/qr' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
            self::SENDER . '/logout' => Http::response(['ok' => true]),
        ]);

        $this->getJson(route('settings.whatsapp.status'))->assertJson(['state' => 'QR']);
        $this->get(route('settings.whatsapp.qr'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->post(route('settings.whatsapp.logout'))->assertSessionHas('success');

        Http::fake(fn () => throw new ConnectionException('refused'));
        $this->getJson(route('settings.whatsapp.status'))->assertJson(['state' => 'OFFLINE']);
        $this->get(route('settings.whatsapp.qr'))->assertNotFound();
    }

    public function test_sender_setup_problems_are_explained(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        Http::fake();

        $this->post(route('settings.whatsapp.test'))->assertSessionHasErrors('whatsapp');
        $this->assertStringContainsString('Belum ada penerima', session('errors')->first('whatsapp'));

        $this->member('Andi', '081200001111');
        config(['services.whatsapp_sender.key' => null]);
        $this->post(route('settings.whatsapp.test'))->assertSessionHasErrors('whatsapp');
        $this->assertStringContainsString('WA_SENDER_KEY', session('errors')->first('whatsapp'));
        Http::assertNothingSent();
    }

    public function test_fonnte_driver_sends_to_all_recipients_at_once(): void
    {
        $this->member('Andi', '081200001111');
        WhatsApp::saveDriver('fonnte');
        WhatsApp::saveToken('Ab12Cd34!Ef56Gh78+Ij');
        WhatsApp::saveExtraTargets('089900005555');
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true])]);

        $this->assertNull(WhatsApp::send('halo'));
        Http::assertSent(fn (Request $r) => $r->url() === WhatsApp::FONNTE_URL
            && $r->hasHeader('Authorization', 'Ab12Cd34!Ef56Gh78+Ij')
            && $r['target'] === '6281200001111,6289900005555');
    }

    public function test_daily_command_sends_low_stock_list_only_when_needed(): void
    {
        Http::fake([self::SENDER . '/send' => Http::response(['ok' => true, 'results' => [['to' => '6281200001111', 'ok' => true]]])]);

        // Belum ada penerima: tidak menghubungi pengirim
        $this->artisan('wms:kirim-stok-menipis')->assertSuccessful();
        Http::assertNothingSent();

        $this->member('Andi', '081200001111');
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
