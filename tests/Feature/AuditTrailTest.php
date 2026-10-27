<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Riwayat Aktivitas: aksi owner yang sensitif tercatat dengan nilai sebelum dan sesudah,
 * dan halamannya bisa disaring serta diekspor.
 */
class AuditTrailTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function userForm(array $override = []): array
    {
        return $override + [
            'name' => 'Rina Gudang',
            'email' => 'rina@test.id',
            'password' => 'rahasia-sekali-123',
            'password_confirmation' => 'rahasia-sekali-123',
            'role' => 'STAFF_GUDANG',
            'workspace_default' => 'GUDANG',
            'status' => 'AKTIF',
        ];
    }

    public function test_user_management_is_recorded_without_passwords(): void
    {
        $owner = $this->userWithRole('OWNER');
        $this->actingAs($owner)->post(route('users.store'), $this->userForm())->assertSessionHasNoErrors();
        $rina = User::where('email', 'rina@test.id')->firstOrFail();

        $created = AuditTrail::where('module', 'USER')->where('action', 'CREATE')->firstOrFail();
        $this->assertSame('STAFF_GUDANG', $created->new_values['role']);
        $this->assertArrayNotHasKey('password', $created->new_values);

        // Naik jadi supervisor dan ganti password: hanya yang berubah dicatat
        $this->put(route('users.update', $rina->id), $this->userForm(['role' => 'SUPERVISOR', 'password' => 'password-baru-123', 'password_confirmation' => 'password-baru-123']));
        $updated = AuditTrail::where('module', 'USER')->where('action', 'UPDATE')->firstOrFail();
        $this->assertSame(['role' => 'STAFF_GUDANG'], $updated->old_values);
        $this->assertSame('SUPERVISOR', $updated->new_values['role']);
        $this->assertSame('diganti', $updated->new_values['password']);
        $this->assertStringNotContainsString('password-baru-123', json_encode($updated->new_values));
        $this->assertArrayNotHasKey('email', $updated->new_values);

        $this->patch(route('users.toggle-status', $rina->id));
        $this->assertSame('NONAKTIF', AuditTrail::where('action', 'TOGGLE_STATUS')->firstOrFail()->new_values['status']);

        $this->delete(route('users.destroy', $rina->id));
        $this->assertTrue(AuditTrail::where('module', 'USER')->where('action', 'DELETE')->exists());

        // Nama user yang sudah dihapus tetap tampil di riwayat aksinya
        AuditTrail::record('LOGIN', 'AUTH', ['email' => 'rina@test.id'], null, $rina->id);
        $this->get(route('audit-trails.index', ['user' => $rina->id]))->assertOk()->assertSee('Rina Gudang');
    }

    public function test_supplier_settings_and_login_are_recorded(): void
    {
        $owner = $this->userWithRole('OWNER');
        $supplier = $this->makeSupplier();
        $this->actingAs($owner)->put(route('suppliers.update', $supplier->id), [
            'name' => 'JB Printing Baru', 'type' => 'SUBLIMASI', 'status' => 'AKTIF',
        ]);
        $log = AuditTrail::where('module', 'SUPPLIER')->where('action', 'UPDATE')->firstOrFail();
        $this->assertSame('JB Printing Baru', $log->new_values['name']);
        $this->assertSame($supplier->name, $log->old_values['name']);

        $this->put(route('settings.update'), ['company_name' => 'JERSIC.ID', 'low_stock_threshold' => 7]);
        $this->assertSame('7', (string) AuditTrail::where('module', 'SETTINGS')->firstOrFail()->new_values['low_stock_threshold']);

        $this->post(route('settings.whatsapp.token'));
        $token = AuditTrail::where('action', 'NEW_TOKEN')->firstOrFail();
        $this->assertStringNotContainsString((string) session('whatsapp_token'), json_encode($token->new_values));

        // Login gagal dan berhasil, lalu logout
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $owner->email, 'password' => 'salah'])->assertSessionHasErrors('email');
        $failed = AuditTrail::where('action', 'LOGIN_FAILED')->firstOrFail();
        $this->assertNull($failed->user_id);
        $this->assertSame($owner->email, $failed->new_values['email']);
        $this->assertTrue(AuditTrail::where('action', 'LOGOUT')->where('user_id', $owner->id)->exists());
    }

    public function test_activity_page_filters_and_exports(): void
    {
        $owner = $this->userWithRole('OWNER');
        $staff = $this->userWithRole('STAFF_GUDANG');
        AuditTrail::record('CREATE', 'SUPPLIER', ['supplier' => 'Vendor Kaos'], null, $owner->id);
        AuditTrail::record('REPORT_MISSING', 'PICKING', ['sku' => 'JSC-HILANG-L'], null, $staff->id);
        AuditTrail::create(['user_id' => $staff->id, 'action' => 'UPDATE_STATUS', 'module' => 'OUTBOUND',
            'old_values' => json_encode(['status' => 'PENDING']), 'new_values' => json_encode(['status' => 'CONFIRMED', 'order_number' => 'ORD-LAMA'])]);

        $this->actingAs($staff)->get(route('audit-trails.index'))->assertForbidden();

        $this->actingAs($this->userWithRole('SUPERVISOR'));
        $this->get(route('audit-trails.index'))->assertOk()
            ->assertSee('Vendor Kaos')->assertSee('JSC-HILANG-L')
            ->assertSee('Lapor barang tidak ada')->assertSee('Picking')
            ->assertSee('ORD-LAMA')->assertSee('PENDING');   // catatan lama (teks JSON) tetap terbaca

        $this->get(route('audit-trails.index', ['module' => 'PICKING']))->assertSee('JSC-HILANG-L')->assertDontSee('Vendor Kaos');
        $this->get(route('audit-trails.index', ['user' => $owner->id]))->assertSee('Vendor Kaos')->assertDontSee('JSC-HILANG-L');
        $this->get(route('audit-trails.index', ['q' => 'HILANG']))->assertSee('JSC-HILANG-L')->assertDontSee('Vendor Kaos');
        $this->get(route('audit-trails.index', ['from' => now()->addDay()->toDateString()]))->assertSee('Tidak ada aktivitas');

        $this->get(route('audit-trails.index', ['export' => 1]))->assertOk()->assertDownload();
    }
}
