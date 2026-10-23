<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Login, hak akses per role, dan semua halaman menu utama terbuka tanpa error.
 */
class AccessAndPagesTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private const WAREHOUSE_PAGES = [
        'dashboard.gudang',
        'gudang.inbound',
        'gudang.inbound.create',
        'gudang.outbound',
        'gudang.outbound.create',
        'gudang.retur',
        'gudang.retur.create',
        'gudang.picking',
        'gudang.picking.create',
        'gudang.packing',
        'gudang.packing.create',
        'gudang.transfer',
        'gudang.opname',
        'gudang.opname.create',
        'gudang.scanner',
        'gudang.printer',
        'gudang.closing',
        'gudang.mapping',
        'settings.update-profile',
    ];

    private const MANAGEMENT_PAGES = [
        'dashboard.owner',
        'master-skus.create',
        'suppliers.index',
        'suppliers.create',
        'po.index',
        'po.create',
        'reports.index',
        'reports.stock',
        'reports.movement',
        'reports.opname',
        'settings.index',
        'users.index',
        'users.create',
        'audit-trails.index',
    ];

    public function test_active_user_can_login_and_lands_on_their_dashboard(): void
    {
        $owner = $this->userWithRole('OWNER');
        $admin = $this->userWithRole('ADMIN_GUDANG');

        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard.owner'));
        $this->post('/logout');

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard.gudang'));
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->userWithRole('STAFF_GUDANG');
        $user->update(['status' => 'NONAKTIF']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_public_registration_is_closed(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_owner_can_open_every_menu_page(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));

        foreach ([...self::WAREHOUSE_PAGES, ...self::MANAGEMENT_PAGES] as $route) {
            $this->get(route($route))->assertOk();
        }

        // Daftar Master SKU memakai GROUP_CONCAT ... SEPARATOR khusus MySQL
        if (config('database.default') === 'mysql') {
            $this->get(route('master-skus.index'))->assertOk();
        }
    }

    public function test_admin_gudang_can_open_warehouse_pages_and_po(): void
    {
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'));

        foreach ([...self::WAREHOUSE_PAGES, 'po.index'] as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->get(route('dashboard.gudang'))->assertSee('Inbound')->assertSee('Purchase Order');
        $this->get(route('master-skus.index'))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
    }

    public function test_staff_gudang_is_limited_to_warehouse_pages(): void
    {
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));

        foreach (self::WAREHOUSE_PAGES as $route) {
            $this->get(route($route))->assertOk();
        }

        foreach (['master-skus.index', 'po.index', 'reports.index', 'users.index', 'settings.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_master_sku_reset_endpoint_is_gone(): void
    {
        // Dulu bisa menghapus semua SKU dan stok dengan satu POST; tidak ada tombolnya, jadi dihapus
        $this->actingAs($this->userWithRole('OWNER'))
            ->post('/master-skus/reset')
            ->assertStatus(405);
    }
}
