<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Tampilan HP & tablet: tinggi layar mengikuti layar yang terlihat (bukan 100vh saja),
 * dan sidebar punya tombol tutup sendiri di HP/tablet tegak.
 */
class ResponsiveLayoutTest extends TestCase
{
    use CreatesWarehouseData;
    use RefreshDatabase;

    public function test_layout_uses_visible_screen_height_and_has_close_button(): void
    {
        $html = $this->actingAs($this->userWithRole('OWNER'))
            ->get(route('dashboard.owner'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('height: 100dvh', $html);
        $this->assertStringContainsString('class="app-shell flex overflow-hidden"', $html);
        $this->assertStringNotContainsString('style="height: 100vh;"', $html);
        $this->assertStringContainsString('aria-label="Tutup menu"', $html);
    }
}
