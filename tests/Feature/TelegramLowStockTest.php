<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\Telegram;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Notifikasi stok menipis lewat bot Telegram.
 */
class TelegramLowStockTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0';

    private function configure(): void
    {
        Telegram::saveToken(self::TOKEN);
        Telegram::saveChatId('-1001234567');
    }

    public function test_owner_saves_telegram_settings_with_encrypted_token(): void
    {
        $owner = $this->userWithRole('OWNER');
        $this->actingAs($owner)->get(route('settings.index'))->assertOk()->assertSee('Notifikasi Telegram')->assertSee('Belum aktif');

        $this->put(route('settings.telegram'), ['telegram_bot_token' => self::TOKEN, 'telegram_chat_id' => '-1001234567'])
            ->assertSessionHas('success');

        $this->assertNotSame(self::TOKEN, SystemSetting::get('telegram_bot_token'));
        $this->assertSame(self::TOKEN, Telegram::token());
        $this->get(route('settings.index'))->assertSee('Aktif')->assertDontSee(self::TOKEN);

        // Token dikosongkan saat simpan ulang: token lama tetap
        $this->put(route('settings.telegram'), ['telegram_bot_token' => '', 'telegram_chat_id' => '987654'])->assertSessionHas('success');
        $this->assertSame(self::TOKEN, Telegram::token());
        $this->assertSame('987654', Telegram::chatId());

        $this->put(route('settings.telegram'), ['telegram_bot_token' => 'bukan token', 'telegram_chat_id' => 'abc'])
            ->assertSessionHasErrors(['telegram_bot_token', 'telegram_chat_id']);

        $this->put(route('settings.telegram'), ['telegram_clear' => 1])->assertSessionHas('success');
        $this->assertFalse(Telegram::configured());

        $this->actingAs($this->userWithRole('SUPERVISOR'))
            ->put(route('settings.telegram'), ['telegram_chat_id' => '1234'])->assertForbidden();
    }

    public function test_chat_id_is_detected_from_the_bot_and_test_message_is_sent(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        Telegram::saveToken(self::TOKEN);
        Http::fake([
            '*/getUpdates' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 111, 'first_name' => 'Lama']]],
                ['update_id' => 2, 'message' => ['chat' => ['id' => -1009876, 'title' => 'Gudang JERSIC']]],
            ]]),
            '*/sendMessage' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->post(route('settings.telegram.detect'))->assertSessionHas('success');
        $this->assertSame('-1009876', Telegram::chatId());

        $this->post(route('settings.telegram.test'))->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'bot' . self::TOKEN . '/sendMessage')
            && $r['chat_id'] === '-1009876' && str_contains($r['text'], 'tidak ada stok menipis'));
    }

    public function test_telegram_errors_are_shown_instead_of_crashing(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $this->configure();
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

        $this->post(route('settings.telegram.test'))->assertSessionHasErrors('telegram');
        $this->assertStringContainsString('chat not found', session('errors')->first('telegram'));

        Http::fake(['*/getUpdates' => Http::response(['ok' => true, 'result' => []])]);
        $this->post(route('settings.telegram.detect'))->assertSessionHasErrors('telegram');
    }

    public function test_daily_command_sends_low_stock_list_only_when_needed(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);

        // Belum diatur: tidak menghubungi Telegram
        $this->artisan('wms:kirim-stok-menipis')->assertSuccessful();
        Http::assertNothingSent();

        $this->configure();
        $this->makeSku('JSC-A-L')->update(['min_stock' => 5]);
        $this->putStock('JSC-A-L', 'A-01-01', 10);

        // Tidak ada yang menipis: tidak ada pesan
        $this->artisan('wms:kirim-stok-menipis')->expectsOutput('Tidak ada stok menipis.')->assertSuccessful();
        Http::assertNothingSent();

        $this->makeSku('JSC-B-<L>')->update(['min_stock' => 4]);
        $this->putStock('JSC-B-<L>', 'A-01-02', 2);

        $this->artisan('wms:kirim-stok-menipis')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Stok menipis: 1 SKU')
            && str_contains($r['text'], '<code>JSC-B-&lt;L&gt;</code>')
            && str_contains($r['text'], 'sisa <b>2</b> (batas 4)')
            && !str_contains($r['text'], 'JSC-A-L'));
        Http::assertSentCount(1);

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command . ' @ ' . $e->expression);
        $this->assertTrue($events->contains(fn ($e) => str_contains($e, 'wms:kirim-stok-menipis') && str_ends_with($e, '0 8 * * *')));
    }
}
