<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Foundation\Support\DocumentPdf;
use App\Modules\Foundation\Support\DocumentRenderToken;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download any document as a PDF.
 *
 * ⭐⭐ ONE ROUTE FOR EVERY DOCUMENT, AND IT OWNS NO PERMISSIONS. It mints a
 * one-shot token, points a headless browser at the document's own print page,
 * and streams what comes back. The permission that decides whether the person
 * may see that document is the one already on the print route - so this can
 * never drift out of step with it, and adding a document here cannot
 * accidentally widen who may read it.
 *
 * ⚠⚠ THE TYPE IS A WHITELIST, NOT A PARAMETER. Without it this becomes a
 * server-side fetcher pointed at an arbitrary URL by whoever calls it, which is
 * a hole big enough to read the cloud metadata service through. Every entry
 * below names a route this application defines.
 */
class DocumentPdfController extends Controller
{
    /**
     * type => [route name, route parameter, model (optional), number column].
     *
     * ⚠ A model is optional because two of these documents have none: the
     * quotation and the HR letter are read straight from their tables and their
     * controllers scope by business by hand. Where a model does exist it earns
     * its place twice over - it names the file after the document rather than
     * its id, and being business-scoped it 404s an id belonging to another
     * company before a browser is ever started.
     */
    private const DOCUMENTS = [
        'invoice' => ['sales.invoices.print', 'invoice', SalesInvoice::class, 'invoice_number'],
        'receipt' => ['sales.receipts.print', 'receipt', CustomerReceipt::class, null],
        'delivery' => ['sales.deliveries.print', 'delivery', Delivery::class, 'delivery_number'],
        'purchase-order' => ['purchasing.purchase-orders.print', 'purchaseOrder', PurchaseOrder::class, 'po_number'],
        'expense-voucher' => ['purchasing.expenses.print', 'voucher', ExpenseVoucher::class, 'voucher_number'],
        'income-voucher' => ['accounting.other-income.print', 'voucher', IncomeVoucher::class, 'voucher_number'],
        'expense-claim' => ['hrm.expense-claims.print', 'claim', ExpenseClaim::class, 'claim_number'],
        'quotation' => ['sales.quotations.print', 'quotation', null, null],
        'hr-letter' => ['hrm.letters.print', 'letter', null, null],
    ];

    public function __invoke(Request $request, string $type, int $id, DocumentPdf $pdf): Response
    {
        abort_unless(array_key_exists($type, self::DOCUMENTS), 404);

        if (! $pdf->isAvailable()) {
            // ⚠⚠ 501, NOT 503. `abort(503)` renders Laravel's maintenance page,
            // which tells the reader "the system is being updated and will be
            // back shortly" - untrue, and it sends them away from a document
            // they could have printed. A plain response says the real thing.
            //
            // ⚠ This is a backstop. The button is not offered at all when no
            // browser is installed; reaching here means somebody kept the URL.
            return response(
                'PDF export is not available on this server. Use Print and choose Save as PDF.',
                501,
                ['Content-Type' => 'text/plain'],
            );
        }

        [$routeName, $parameter, $model, $numberColumn] = self::DOCUMENTS[$type];

        $number = null;

        if ($model !== null) {
            // Business-scoped by the model, so another company's id is a 404
            // here, before anything is rendered.
            $record = $model::query()->findOrFail($id);
            $number = $numberColumn === null ? null : $record->{$numberColumn};
        }

        $target = route($routeName, [$parameter => $id]);
        $path = trim(parse_url($target, PHP_URL_PATH) ?? '', '/');

        $token = DocumentRenderToken::mint(
            (int) $request->user()->id,
            $request->session()->get('current_business_id'),
            $path,
        );

        $base = rtrim((string) (config('pdf.base_url') ?: config('app.url')), '/');
        $url = $base.'/'.$path.'?render_token='.$token;

        try {
            $bytes = $pdf->render($url);
        } catch (RuntimeException $e) {
            report($e);
            abort(500, 'The document could not be turned into a PDF. Use Print and choose Save as PDF.');
        }

        $status = DocumentRenderToken::outcome($token);

        // ⚠⚠ A BROWSER PRINTS A 403 AS HAPPILY AS AN INVOICE. Without this the
        // caller is handed a neatly typeset PDF of an error page and no clue
        // that anything went wrong.
        if ($status !== 200) {
            abort($status === 403 ? 403 : 500, $status === 403
                ? 'You do not have permission to read that document.'
                : 'The document did not render. Nothing has been downloaded.');
        }

        $filename = $this->filename($type, $number, $id);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // A document is a snapshot of a live record; a cached copy would
            // quietly hand somebody yesterday's totals.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function filename(string $type, ?string $number, int $id): string
    {
        // ⚠ The document number, not the row id, whenever there is one: a
        // folder of invoice-11.pdf tells nobody anything. Reduced to characters
        // a filesystem and a Content-Disposition header both accept.
        $stem = $number !== null && $number !== ''
            ? preg_replace('/[^A-Za-z0-9._-]/', '-', $number)
            : $type.'-'.$id;

        return $stem.'.pdf';
    }
}
