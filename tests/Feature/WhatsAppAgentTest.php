<?php

namespace Tests\Feature;

use App\Services\WhatsApp;
use App\Services\WhatsAppAgent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Agen WhatsApp ringan dikendalikan dari WMS: QR tampil di Pengaturan, status, perintah, dan grup.
 */
class WhatsAppAgentTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function agent(string $token, array $body)
    {
        return $this->postJson(route('api.whatsapp.agent'), $body, ['Authorization' => 'Bearer ' . $token]);
    }

    public function test_agent_reports_qr_then_connection_and_owner_sees_it(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        $owner = $this->userWithRole('OWNER');
        $token = WhatsApp::generateToken();

        $this->postJson(route('api.whatsapp.agent'), ['state' => 'qr'])->assertUnauthorized();

        // Belum ada laporan: agen dianggap tidak berjalan, dengan petunjuk menjalankannya
        $this->actingAs($owner)->get(route('settings.index'))->assertOk()
            ->assertSee('Koneksi WhatsApp')->assertSee('Agen tidak berjalan')->assertSee('npm run setup');

        $this->agent($token, ['state' => 'qr', 'qr' => '2@abcdef,ghijk', 'version' => '1.0.0', 'memory_mb' => 95])
            ->assertOk()->assertExactJson(['command' => null]);

        $status = $this->getJson(route('settings.whatsapp.agent'))->assertOk()
            ->assertJson(['state' => 'qr', 'label' => 'Menunggu scan QR', 'memory_mb' => 95])->json();
        $this->assertStringContainsString('<svg', $status['qr_svg']);

        $this->agent($token, [
            'state' => 'connected',
            'phone' => '6281234567890',
            'name' => 'JERSIC Gudang',
            'groups' => [['id' => '1203@g.us', 'name' => 'Gudang Jersic'], ['id' => '1204@g.us', 'name' => 'Admin & Owner'], ['bad' => 1]],
        ])->assertOk();

        $this->getJson(route('settings.whatsapp.agent'))
            ->assertJson(['state' => 'connected', 'label' => 'Tersambung', 'qr_svg' => null, 'phone' => '+6281234567890', 'name' => 'JERSIC Gudang', 'groups' => 2]);
        $this->assertSame(['Admin & Owner', 'Gudang Jersic'], WhatsAppAgent::groupNames());
        $this->assertNotNull(WhatsApp::macroSeenAt());

        // Grup bisa dipilih langsung sebagai penerima laporan harian
        $this->get(route('settings.index'))->assertSee('Pilih grup dari nomor pengirim')->assertSee('<option value="Gudang Jersic">', false);

        // Tidak melapor lebih dari 70 detik: tidak berjalan
        Carbon::setTestNow('2026-11-03 09:01:30');
        $this->getJson(route('settings.whatsapp.agent'))->assertJson(['state' => 'offline', 'label' => 'Agen tidak berjalan', 'phone' => '+6281234567890']);
    }

    public function test_owner_commands_are_picked_up_once_by_the_agent(): void
    {
        $token = WhatsApp::generateToken();
        $this->actingAs($this->userWithRole('OWNER'));

        $this->post(route('settings.whatsapp.agent.command'), ['command' => 'hapus-semua'])->assertSessionHasErrors('command');
        $this->post(route('settings.whatsapp.agent.command'), ['command' => 'logout'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'QR baru muncul'));
        $this->assertDatabaseHas('audit_trails', ['action' => 'WHATSAPP_AGENT']);
        $this->getJson(route('settings.whatsapp.agent'))->assertJson(['pending' => 'logout']);

        $this->agent($token, ['state' => 'connected'])->assertExactJson(['command' => 'logout']);
        $this->agent($token, ['state' => 'connecting'])->assertExactJson(['command' => null]);

        $this->postJson(route('settings.whatsapp.agent.command'), ['command' => 'refresh_groups'])->assertOk()->assertJson(['ok' => true]);
        $this->agent($token, ['state' => 'connected'])->assertExactJson(['command' => 'refresh_groups']);

        $this->actingAs($this->userWithRole('SUPERVISOR'))->post(route('settings.whatsapp.agent.command'), ['command' => 'restart'])->assertForbidden();
        $this->getJson(route('settings.whatsapp.agent'))->assertForbidden();
    }
}
