<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp;
use App\Services\WhatsAppAgent;
use Illuminate\Http\Request;

/**
 * API untuk makro Playwright: ambil pesan WhatsApp yang menunggu, lalu laporkan hasilnya.
 * Wajib header "Authorization: Bearer <token>" (token dibuat owner di Pengaturan).
 */
class WhatsAppQueueController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeMacro($request);
        $limit = max(1, min(50, (int) $request->query('limit', 10)));

        return response()->json([
            'messages' => WhatsApp::claim($limit)->map(fn (WhatsAppMessage $m) => [
                'id' => $m->id,
                'to' => $m->recipient,
                'message' => $m->body,
            ])->values(),
        ]);
    }

    // Id pesan dicari SETELAH token dicek: tanpa token, id yang ada dan tidak ada sama-sama dijawab 401
    public function sent(Request $request, string $message)
    {
        $this->authorizeMacro($request);
        $message = WhatsAppMessage::findOrFail($message);
        WhatsApp::markSent($message);

        return response()->json(['ok' => true]);
    }

    public function failed(Request $request, string $message)
    {
        $this->authorizeMacro($request);
        $message = WhatsAppMessage::findOrFail($message);
        $error = (string) $request->input('error', 'tidak diketahui');
        WhatsApp::markFailed($message, $error);

        return response()->json(['ok' => true, 'status' => $message->fresh()->status]);
    }

    /**
     * Laporan status dari agen WhatsApp ringan (whatsapp-agent/): QR, tersambung, grup.
     * Balasannya perintah dari owner (putuskan / sambung ulang / muat ulang grup), atau null.
     */
    public function agent(Request $request)
    {
        $this->authorizeMacro($request);
        $validated = $request->validate([
            'state' => ['required', 'string', 'max:20'],
            'qr' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'name' => ['nullable', 'string', 'max:100'],
            'groups' => ['nullable', 'array', 'max:300'],
            'version' => ['nullable', 'string', 'max:20'],
            'memory_mb' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['command' => WhatsAppAgent::report($validated + ['groups' => $request->input('groups', [])])]);
    }

    private function authorizeMacro(Request $request): void
    {
        abort_unless(WhatsApp::tokenValid($request->bearerToken() ?? $request->header('X-Api-Key')), 401, 'Token makro salah atau belum dibuat.');
    }
}
