<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Notifikasi stok menipis lewat WhatsApp: antrean pesan yang diambil makro Playwright milik toko.
 */
class WhatsAppLowStockTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

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

    public function test_owner_creates_macro_token_and_sees_queue(): void
    {
        $this->member('Andi Picker', '081200001111');
        $this->actingAs($this->userWithRole('OWNER'));

        $this->get(route('settings.index'))->assertOk()->assertSee('Notifikasi WhatsApp')->assertSee('Penerima: 1 anggota')
            ->assertSee('Andi Picker')->assertSee('Belum aktif')->assertSee('belum pernah');

        $response = $this->post(route('settings.whatsapp.token'))->assertSessionHas('whatsapp_token');
        $token = session('whatsapp_token');
        $this->assertStringStartsWith('wa_', $token);
        $this->assertNotSame($token, SystemSetting::get('whatsapp_macro_token'));
        $this->assertTrue(WhatsApp::tokenValid($token));

        $this->followRedirects($response)->assertSee($token)->assertSee('Aktif');
        $this->get(route('settings.index'))->assertDontSee($token);

        // Token baru membatalkan token lama
        $this->post(route('settings.whatsapp.token'));
        $this->assertFalse(WhatsApp::tokenValid($token));

        $this->put(route('settings.whatsapp'), ['whatsapp_target' => 'nomor saya'])->assertSessionHasErrors('whatsapp_target');
        $this->put(route('settings.whatsapp'), ['whatsapp_target' => '089900005555'])->assertSessionHas('success');

        $this->actingAs($this->userWithRole('SUPERVISOR'))->post(route('settings.whatsapp.token'))->assertForbidden();
    }

    public function test_test_message_is_queued_per_recipient(): void
    {
        $this->member('Andi', '081200001111');
        $this->member('Budi', '081200002222');
        $this->actingAs($this->userWithRole('OWNER'));

        $this->post(route('settings.whatsapp.test'))->assertSessionHas('success');
        $this->assertSame(['6281200001111', '6281200002222'], WhatsAppMessage::orderBy('id')->pluck('recipient')->all());
        $this->assertStringContainsString('tidak ada stok menipis', WhatsAppMessage::first()->body);
        $this->get(route('settings.index'))->assertSee('10 pesan terakhir')->assertSee('Menunggu');
    }

    public function test_macro_takes_queue_and_reports_results(): void
    {
        $token = WhatsApp::generateToken();
        $this->member('Andi', '081200001111');
        $this->member('Budi', '081200002222');
        $this->member('Citra', '081200003333');
        WhatsApp::send('*Stok menipis*');
        $auth = ['Authorization' => 'Bearer ' . $token];

        // Tanpa token / token salah
        $this->getJson(route('api.whatsapp.queue'))->assertUnauthorized();
        $this->getJson(route('api.whatsapp.queue'), ['Authorization' => 'Bearer salah'])->assertUnauthorized();

        $first = $this->getJson(route('api.whatsapp.queue', ['limit' => 2]), $auth)->assertOk()->json('messages');
        $this->assertSame([['id' => $first[0]['id'], 'to' => '6281200001111', 'message' => '*Stok menipis*'], ['id' => $first[1]['id'], 'to' => '6281200002222', 'message' => '*Stok menipis*']], $first);
        $this->assertNotNull(WhatsApp::macroSeenAt());

        // Yang sudah diambil tidak diberikan lagi
        $this->assertSame(['6281200003333'], collect($this->getJson(route('api.whatsapp.queue'), $auth)->json('messages'))->pluck('to')->all());
        $this->assertSame([], $this->getJson(route('api.whatsapp.queue'), $auth)->json('messages'));

        $this->postJson(route('api.whatsapp.sent', $first[0]['id']), [], $auth)->assertOk();
        $this->assertSame('SENT', WhatsAppMessage::find($first[0]['id'])->status);

        // Gagal: dicoba lagi sampai 3 kali
        $this->postJson(route('api.whatsapp.failed', $first[1]['id']), ['error' => 'nomor tidak terdaftar'], $auth)->assertJson(['status' => 'PENDING']);
        $this->assertSame(['6281200002222'], collect($this->getJson(route('api.whatsapp.queue'), $auth)->json('messages'))->pluck('to')->all());
        $this->postJson(route('api.whatsapp.failed', $first[1]['id']), ['error' => 'x'], $auth);
        $this->postJson(route('api.whatsapp.failed', $first[1]['id']), ['error' => 'nomor tidak terdaftar'], $auth)->assertJson(['status' => 'FAILED']);
        $this->assertSame('nomor tidak terdaftar', WhatsAppMessage::find($first[1]['id'])->error);

        // Diambil tapi tidak dilaporkan (makro mati): diberikan lagi setelah 10 menit
        $this->travel(11)->minutes();
        $this->assertSame(['6281200003333'], collect($this->getJson(route('api.whatsapp.queue'), $auth)->json('messages'))->pluck('to')->all());
    }

    public function test_without_recipients_nothing_is_queued(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));

        $this->post(route('settings.whatsapp.test'))->assertSessionHasErrors('whatsapp');
        $this->assertStringContainsString('Belum ada penerima', session('errors')->first('whatsapp'));
        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_daily_command_queues_low_stock_list_only_when_needed(): void
    {
        // Belum ada token/penerima: tidak ada yang diantrekan
        $this->artisan('wms:kirim-stok-menipis')->assertSuccessful();
        $this->assertSame(0, WhatsAppMessage::count());

        WhatsApp::generateToken();
        $this->member('Andi', '081200001111');
        $this->makeSku('JSC-A-L')->update(['min_stock' => 5]);
        $this->putStock('JSC-A-L', 'A-01-01', 10);

        // Tidak ada yang menipis: tidak ada pesan
        $this->artisan('wms:kirim-stok-menipis')->expectsOutput('Tidak ada stok menipis.')->assertSuccessful();
        $this->assertSame(0, WhatsAppMessage::count());

        $this->makeSku('JSC-B-L')->update(['min_stock' => 4]);
        $this->putStock('JSC-B-L', 'A-01-02', 2);

        $this->artisan('wms:kirim-stok-menipis')->assertSuccessful();
        $body = WhatsAppMessage::sole()->body;
        $this->assertStringContainsString('*Stok menipis: 1 SKU*', $body);
        $this->assertStringContainsString('JSC-B-L CLUB TEST', $body);
        $this->assertStringContainsString('sisa *2* (batas 4)', $body);
        $this->assertStringContainsString('dari=stok-menipis', $body);
        $this->assertStringNotContainsString('JSC-A-L', $body);

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command . ' @ ' . $e->expression);
        $this->assertTrue($events->contains(fn ($e) => str_contains($e, 'wms:kirim-stok-menipis') && str_ends_with($e, '0 8 * * *')));
    }
}
