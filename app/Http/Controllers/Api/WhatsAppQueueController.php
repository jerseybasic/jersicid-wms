<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp;
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

    public function sent(Request $request, WhatsAppMessage $message)
    {
        $this->authorizeMacro($request);
        WhatsApp::markSent($message);

        return response()->json(['ok' => true]);
    }

    public function failed(Request $request, WhatsAppMessage $message)
    {
        $this->authorizeMacro($request);
        $error = (string) $request->input('error', 'tidak diketahui');
        WhatsApp::markFailed($message, $error);

        return response()->json(['ok' => true, 'status' => $message->fresh()->status]);
    }

    private function authorizeMacro(Request $request): void
    {
        abort_unless(WhatsApp::tokenValid($request->bearerToken() ?? $request->header('X-Api-Key')), 401, 'Token makro salah atau belum dibuat.');
    }
}
