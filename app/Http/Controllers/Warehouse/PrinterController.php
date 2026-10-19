<?php

namespace App\Http\Controllers\Warehouse;

use App\Rules\LanPrinterIp;
use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\PrinterTemplate;
use App\Services\ZebraPrinterService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PrinterController extends Controller
{
    protected $printerService;

    public function __construct(ZebraPrinterService $printerService)
    {
        $this->printerService = $printerService;
    }

    public function index()
    {
        $templates = PrinterTemplate::orderBy('name')->get();

        $skus = MasterSku::where('status', 'AKTIF')
            ->orderBy('product_name')
            ->get(['sku', 'product_name', 'size', 'article', 'price']);

        return view('warehouse.printer.index', compact('templates', 'skus'));
    }

    /**
     * Preview Label dalam HTML
     */
    public function previewLabel(Request $request)
    {
        try {
            $validated = $request->validate([
                'sku' => 'required|exists:master_skus,sku',
                'qty' => 'required|integer|min:1',
            ]);

            $sku = MasterSku::where('sku', $validated['sku'])->firstOrFail();

            // Generate HTML preview
            $html = $this->printerService->generateHtmlPreview($sku, $validated['qty']);

            // Generate ZPL code
            $zpl = $this->printerService->generateSingleLabel($sku, $validated['qty']);

            return response()->json([
                'success' => true,
                'html' => $html,
                'zpl' => $zpl,
                'sku' => $sku->sku,
                'product_name' => $sku->product_name,
            ]);
        } catch (\Exception $e) {
            Log::error('Preview Label Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    /**
     * Download Label sebagai PDF (format seperti WAJIB VIDEO UNBOXING)
     */
    public function downloadPdf(Request $request)
    {
        try {
            $validated = $request->validate([
                'sku' => 'required|exists:master_skus,sku',
                'qty' => 'required|integer|min:1',
            ]);

            $sku = MasterSku::where('sku', $validated['sku'])->firstOrFail();

            // Generate HTML untuk PDF (format landscape 2 kolom seperti WAJIB VIDEO UNBOXING)
            $html = $this->printerService->generatePdfHtml($sku, $validated['qty']);

            // Wrap dalam HTML lengkap untuk PDF
            $fullHtml = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    @page {
                        size: A4 landscape;
                        margin: 10mm;
                    }
                    body {
                        margin: 0;
                        padding: 0;
                        font-family: Arial, sans-serif;
                    }
                    .page-container {
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        min-height: 190mm;
                    }
                </style>
            </head>
            <body>
                <div class='page-container'>
                    {$html}
                </div>
            </body>
            </html>
            ";

            $pdf = Pdf::loadHTML($fullHtml)
                ->setPaper('A4', 'landscape')
                ->setOption('margin_top', 10)
                ->setOption('margin_right', 10)
                ->setOption('margin_bottom', 10)
                ->setOption('margin_left', 10);

            return $pdf->download('label_' . $sku->sku . '.pdf');
        } catch (\Exception $e) {
            Log::error('Download PDF Error: ' . $e->getMessage());

            return back()->with('error', 'Gagal generate PDF: ' . safe_error_message($e));
        }
    }

    /**
     * Print & Download ZPL (fallback)
     */
    public function printSkuLabel(Request $request)
    {
        try {
            $validated = $request->validate([
                'sku' => 'required|exists:master_skus,sku',
                'qty' => 'required|integer|min:1',
                'printer_ip' => ['nullable', 'string', new LanPrinterIp()],
            ]);

            $sku = MasterSku::where('sku', $validated['sku'])->firstOrFail();
            $zpl = $this->printerService->generateSingleLabel($sku, $validated['qty']);

            if (!empty($validated['printer_ip'])) {
                $success = $this->printerService->sendToNetworkPrinter($zpl, $validated['printer_ip']);

                return response()->json([
                    'success' => $success,
                    'message' => $success ? 'Label berhasil dicetak ke printer' : 'Gagal mengirim ke printer',
                ]);
            }

            return response($zpl)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Disposition', 'attachment; filename="label_' . $sku->sku . '.zpl"');
        } catch (\Exception $e) {
            Log::error('Print SKU Label Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    /**
     * Preview ZPL Code
     */
    public function previewZpl(Request $request)
    {
        try {
            $validated = $request->validate([
                'sku' => 'required|exists:master_skus,sku',
                'qty' => 'required|integer|min:1',
            ]);

            $sku = MasterSku::where('sku', $validated['sku'])->firstOrFail();
            $zpl = $this->printerService->generateSingleLabel($sku, $validated['qty']);

            return response()->json([
                'success' => true,
                'zpl' => $zpl,
                'sku' => $sku->sku,
                'product_name' => $sku->product_name,
            ]);
        } catch (\Exception $e) {
            Log::error('Preview ZPL Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    public function printShippingLabel(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer_name' => 'required|string',
                'address' => 'required|string',
                'phone' => 'nullable|string',
                'courier' => 'nullable|string',
                'tracking_number' => 'required|string',
                'printer_ip' => ['nullable', 'string', new LanPrinterIp()],
            ]);

            $zpl = $this->printerService->generateShippingLabel($validated);

            if (!empty($validated['printer_ip'])) {
                $success = $this->printerService->sendToNetworkPrinter($zpl, $validated['printer_ip']);

                return response()->json([
                    'success' => $success,
                    'message' => $success ? 'Label pengiriman dicetak' : 'Gagal mencetak',
                ]);
            }

            return response()->json([
                'success' => true,
                'zpl' => $zpl,
            ]);
        } catch (\Exception $e) {
            Log::error('Print Shipping Label Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    /**
     * Save Template (Create)
     */
    public function saveTemplate(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'type' => 'required|in:SKU_LABEL,SHIPPING_LABEL,BIN_LABEL,PO_LABEL,CUSTOM',
                'zpl_code' => 'required|string',
                'width_mm' => 'required|integer|min:10|max:200',
                'height_mm' => 'required|integer|min:10|max:200',
                'dpi' => 'required|integer|in:203,300,600',
            ]);

            PrinterTemplate::create([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'zpl_code' => $validated['zpl_code'],
                'width_mm' => $validated['width_mm'],
                'height_mm' => $validated['height_mm'],
                'dpi' => $validated['dpi'],
                'is_active' => true,
                'user_id' => auth()->id(),
            ]);

            return back()->with('success', 'Template printer berhasil disimpan!');
        } catch (\Exception $e) {
            Log::error('Save Template Error: ' . $e->getMessage());

            return back()->with('error', 'Gagal menyimpan template: ' . safe_error_message($e));
        }
    }

    /**
     * Update Template (Edit)
     */
    public function updateTemplate(Request $request, $id)
    {
        try {
            $template = PrinterTemplate::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'type' => 'required|in:SKU_LABEL,SHIPPING_LABEL,BIN_LABEL,PO_LABEL,CUSTOM',
                'zpl_code' => 'required|string',
                'width_mm' => 'required|integer|min:10|max:200',
                'height_mm' => 'required|integer|min:10|max:200',
                'dpi' => 'required|integer|in:203,300,600',
                'is_active' => 'boolean',
            ]);

            $template->update($validated);

            return back()->with('success', 'Template berhasil diupdate!');
        } catch (\Exception $e) {
            Log::error('Update Template Error: ' . $e->getMessage());

            return back()->with('error', 'Gagal update template: ' . safe_error_message($e));
        }
    }

    /**
     * Delete Template
     */
    public function deleteTemplate($id)
    {
        try {
            $template = PrinterTemplate::findOrFail($id);
            $template->delete();

            return response()->json([
                'success' => true,
                'message' => 'Template berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete Template Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    public function testConnection(Request $request)
    {
        try {
            $validated = $request->validate([
                'printer_ip' => ['required', 'string', new LanPrinterIp()],
                'port' => 'nullable|integer|between:9100,9109',
            ]);

            $ip = $validated['printer_ip'];
            $port = $validated['port'] ?? 9100;

            $socket = @fsockopen($ip, $port, $errno, $errstr, 3);

            if ($socket) {
                fclose($socket);

                return response()->json([
                    'success' => true,
                    'message' => "Printer terhubung di {$ip}:{$port}",
                    'ip' => $ip,
                    'port' => $port,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => "Gagal terhubung ke {$ip}:{$port}. Pastikan printer online dan IP benar.",
                'error' => $errstr ?: 'Connection timeout',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Test Connection Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }
}
