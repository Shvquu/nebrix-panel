<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use InvalidArgumentException;
use Nebrix\Finance\Accounting\AccountingExport;
use Nebrix\Finance\Accounting\AccountingSync;
use Nebrix\Http\Controller;
use Nebrix\Http\DocumentResponse;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\ValidationException;
use RuntimeException;

/**
 * Finance → Accounting: DATEV posting batch and CSV exports for a date
 * range, transfer to lexoffice. Permission accounting.export (route); every
 * export and sync is audit-logged.
 */
final class AccountingController extends Controller
{
    public function __construct(
        private readonly AccountingExport $export,
        private readonly AccountingSync $sync,
    ) {
    }

    public function index(Request $request): Response
    {
        $zone = $this->export->timezone();
        $lastMonth = (new \DateTimeImmutable('first day of last month', $zone));

        return $this->render($request, 'admin/finance/accounting.twig', [
            'from' => $lastMonth->format('Y-m-d'),
            'to' => $lastMonth->modify('last day of this month')->format('Y-m-d'),
            'lexoffice_enabled' => $this->sync->enabled(),
            'lexoffice' => $this->sync->status(),
        ]);
    }

    public function download(Request $request): Response
    {
        $data = $this->validate($request, [
            'format' => 'required|in:datev,invoices,payments',
            'from' => 'required|date',
            'to' => 'required|date',
            'description' => 'nullable|string|max:30',
        ]);
        $user = $this->user($request);
        try {
            if ($data['format'] === 'datev') {
                $result = $this->export->datev((string) $data['from'], (string) $data['to'], (string) ($data['description'] ?? ''), $user->displayName());
                $this->audit($request, 'accounting.exported', 'accounting', 'datev', ['from' => $data['from'], 'to' => $data['to'], 'bookings' => $result['bookings'], 'skipped' => count($result['skipped'])]);
                $response = DocumentResponse::make($result['content'], $result['filename'], 'text/csv; charset=windows-1252');
                if ($result['skipped'] !== []) {
                    // Shown with the next page view; the download itself cannot carry a message.
                    $this->flash($request, 'warning', 'Some documents were not exported because they are not in the default currency.');
                }

                return $response;
            }
            $content = $data['format'] === 'invoices'
                ? $this->export->invoicesCsv((string) $data['from'], (string) $data['to'])
                : $this->export->paymentsCsv((string) $data['from'], (string) $data['to']);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($exception instanceof \PDOException) {
                throw $exception;
            }
            throw ValidationException::withMessages(['from' => $exception->getMessage()]);
        }
        $this->audit($request, 'accounting.exported', 'accounting', (string) $data['format'], ['from' => $data['from'], 'to' => $data['to']]);

        return DocumentResponse::make($content, sprintf('%s_%s_%s.csv', $data['format'], str_replace('-', '', (string) $data['from']), str_replace('-', '', (string) $data['to'])), 'text/csv; charset=utf-8');
    }

    /** Transfers pending documents to lexoffice now (also those that failed too often). */
    public function syncNow(Request $request): Response
    {
        if (!$this->sync->enabled()) {
            $this->flash($request, 'error', 'The lexoffice transfer is not enabled or no API key is stored.');

            return $this->redirect('/admin/accounting');
        }
        try {
            $result = $this->sync->run(25, true);
        } catch (\PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            // The lock is held by a running transfer.
            $this->flash($request, 'warning', 'A transfer is already running. Please try again in a minute.');

            return $this->redirect('/admin/accounting');
        }
        $this->audit($request, 'accounting.lexoffice_sync', 'accounting', 'lexoffice', $result);
        $this->flash($request, $result['failed'] > 0 ? 'warning' : 'success', $result['failed'] > 0 ? 'The transfer finished with errors. See the list below.' : 'The transfer to lexoffice is complete.');

        return $this->redirect('/admin/accounting');
    }
}
