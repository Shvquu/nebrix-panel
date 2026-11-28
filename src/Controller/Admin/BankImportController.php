<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use InvalidArgumentException;
use Nebrix\Finance\Bank\BankCsvParser;
use Nebrix\Finance\Bank\BankReconciliation;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\ValidationException;

/**
 * Finance → Bank transfers: statement upload (CAMT.053 or bank CSV), review
 * list, manual assignment, CSV profiles. Permission bank.import (route);
 * every action is audit-logged.
 */
final class BankImportController extends Controller
{
    public function __construct(private readonly BankReconciliation $bank)
    {
    }

    public function index(Request $request): Response
    {
        $status = $request->string('status', BankReconciliation::REVIEW);
        $status = in_array($status, BankReconciliation::STATUSES, true) ? $status : ($status === 'all' ? null : BankReconciliation::REVIEW);
        $page = $this->page($request);

        return $this->render($request, 'admin/finance/bank/index.twig', [
            'status' => $status ?? 'all',
            'statuses' => BankReconciliation::STATUSES,
            'counts' => $this->bank->counts(),
            'result' => $this->bank->entries($status, $page),
            'page' => $page,
            'imports' => $this->bank->imports(),
            'formats' => $this->formats(),
            'max_kb' => intdiv($this->bank->maxBytes(), 1024),
        ]);
    }

    public function import(Request $request): Response
    {
        $data = $this->validate($request, ['format' => 'required|string|max:30']);
        $format = (string) $data['format'];
        if (!array_key_exists($format, $this->formats())) {
            throw ValidationException::withMessages(['format' => 'Choose a file format.']);
        }
        $content = $this->upload($request);
        $user = $this->user($request);
        try {
            [$mapping, $profile] = $format === 'camt053' ? [null, null] : $this->bank->mapping($format);
            $result = $this->bank->import($content, (string) (($request->file('file') ?? [])['name'] ?? 'statement'), $format === 'camt053' ? 'camt053' : 'csv', $mapping, $profile, $user->id, $request->clientIp());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }
        // The numbers of new, booked and open entries are shown in the list of imports.
        $this->flash($request, 'success', $result['review'] > 0 ? 'The statement has been imported. Please check the entries on the review list.' : 'The statement has been imported.');

        return $this->redirect('/admin/bank');
    }

    public function show(Request $request, int $id): Response
    {
        $entry = $this->bank->find($id) ?? throw HttpException::notFound();

        return $this->render($request, 'admin/finance/bank/show.twig', ['entry' => $entry]);
    }

    public function assign(Request $request, int $id): Response
    {
        $this->bank->find($id) ?? throw HttpException::notFound();
        $data = $this->validate($request, [
            'invoice' => 'required|string|max:32|regex:/^[A-Za-z0-9-]+$/',
            'excess_as_credit' => 'bool',
            'note' => 'nullable|string|max:255',
        ]);
        $invoiceId = $this->container->get(\Nebrix\Database\Connection::class)->fetchValue("SELECT `id` FROM `nx_invoices` WHERE `number` = ? AND `type` <> 'credit_note'", [strtoupper((string) $data['invoice'])]);
        if ($invoiceId === null) {
            throw ValidationException::withMessages(['invoice' => 'No invoice with this number.']);
        }
        try {
            $transaction = $this->bank->book($id, (int) $invoiceId, (bool) $data['excess_as_credit'], BankReconciliation::ASSIGNED, $this->user($request)->id, $data['note'] !== null ? (string) $data['note'] : null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }
        $this->audit($request, 'bank.entry_assigned', 'bank_entry', $id, ['invoice' => (int) $invoiceId, 'transaction' => $transaction, 'excess_as_credit' => (bool) $data['excess_as_credit']]);
        $this->flash($request, 'success', 'The payment has been booked.');

        return $this->redirect('/admin/bank/entries/' . $id);
    }

    public function credit(Request $request, int $id): Response
    {
        $this->bank->find($id) ?? throw HttpException::notFound();
        $data = $this->validate($request, ['customer' => 'required|string|max:254', 'note' => 'nullable|string|max:255']);
        $customer = trim((string) $data['customer']);
        $db = $this->container->get(\Nebrix\Database\Connection::class);
        $userId = ctype_digit($customer)
            ? $db->fetchValue("SELECT u.`id` FROM `nx_users` u JOIN `nx_roles` r ON r.`id` = u.`role_id` WHERE u.`id` = ? AND r.`slug` = 'customer' AND u.`status` <> 'closed'", [(int) $customer])
            : $db->fetchValue("SELECT u.`id` FROM `nx_users` u JOIN `nx_roles` r ON r.`id` = u.`role_id` WHERE u.`email` = ? AND r.`slug` = 'customer' AND u.`status` <> 'closed'", [$customer]);
        if ($userId === null) {
            throw ValidationException::withMessages(['customer' => 'No customer with this number or e-mail address.']);
        }
        try {
            $invoice = $this->bank->credit($id, (int) $userId, $this->user($request)->id, $data['note'] !== null ? (string) $data['note'] : null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['customer' => $exception->getMessage()]);
        }
        $this->audit($request, 'bank.entry_credited', 'bank_entry', $id, ['customer' => (int) $userId, 'invoice' => $invoice]);
        $this->flash($request, 'success', 'The amount has been booked as account credit.');

        return $this->redirect('/admin/bank/entries/' . $id);
    }

    public function ignore(Request $request, int $id): Response
    {
        $this->bank->find($id) ?? throw HttpException::notFound();
        $data = $this->validate($request, ['note' => 'required|string|max:255']);
        try {
            $this->bank->ignore($id, $this->user($request)->id, (string) $data['note']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['note' => $exception->getMessage()]);
        }
        $this->audit($request, 'bank.entry_ignored', 'bank_entry', $id, ['note' => (string) $data['note']]);
        $this->flash($request, 'success', 'The entry has been marked as handled.');

        return $this->redirect('/admin/bank');
    }

    public function profiles(Request $request): Response
    {
        $profiles = array_map(static function (array $profile): array {
            $columns = json_decode((string) $profile['mapping'], true);

            return $profile + ['columns' => is_array($columns) ? $columns : []];
        }, $this->bank->profiles());

        return $this->render($request, 'admin/finance/bank/profiles.twig', ['profiles' => $profiles, 'presets' => BankCsvParser::PRESETS]);
    }

    public function saveProfile(Request $request): Response
    {
        $rules = ['name' => 'required|string|max:100', 'delimiter' => 'required|in:semicolon,comma,tab', 'encoding' => 'required|in:UTF-8,Windows-1252,ISO-8859-1', 'date_format' => 'required|in:d.m.Y,d.m.y,Y-m-d,d/m/Y', 'decimal' => 'required|in:comma,point'];
        foreach ([...BankCsvParser::COLUMNS, 'booked_value'] as $column) {
            $rules[$column] = in_array($column, ['booking_date', 'amount', 'remittance'], true) ? 'required|string|max:200' : 'nullable|string|max:100';
        }
        $data = $this->validate($request, $rules);
        $mapping = array_map(static fn (mixed $value): string => (string) $value, array_diff_key($data, ['name' => true]));
        $mapping['delimiter'] = ['semicolon' => ';', 'comma' => ',', 'tab' => 'tab'][$mapping['delimiter']];
        $mapping['decimal'] = $mapping['decimal'] === 'comma' ? ',' : '.';
        try {
            $id = $this->bank->saveProfile(trim((string) $data['name']), $mapping, $this->user($request)->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }
        $this->audit($request, 'bank.profile_saved', 'bank_profile', $id, ['name' => $data['name']]);
        $this->flash($request, 'success', 'The CSV profile has been saved.');

        return $this->redirect('/admin/bank/profiles');
    }

    public function deleteProfile(Request $request, int $id): Response
    {
        $this->bank->deleteProfile($id);
        $this->audit($request, 'bank.profile_deleted', 'bank_profile', $id);
        $this->flash($request, 'success', 'The CSV profile has been deleted.');

        return $this->redirect('/admin/bank/profiles');
    }

    /**
     * @return array<string, string> value => label
     */
    private function formats(): array
    {
        $formats = ['camt053' => 'CAMT.053 (XML)'];
        foreach (BankCsvParser::PRESETS as $key => $preset) {
            $formats['preset:' . $key] = 'CSV: ' . $preset['label'];
        }
        foreach ($this->bank->profiles() as $profile) {
            $formats['profile:' . $profile['id']] = 'CSV: ' . $profile['name'];
        }

        return $formats;
    }

    /** Content of the uploaded statement after size and type checks (the file itself is not stored). */
    private function upload(Request $request): string
    {
        $file = $request->file('file') ?? [];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw ValidationException::withMessages(['file' => $error === UPLOAD_ERR_NO_FILE ? 'Please choose a file.' : 'The upload failed.']);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw ValidationException::withMessages(['file' => 'The upload failed.']);
        }
        $size = filesize($tmp);
        if ($size === false || $size === 0 || $size > $this->bank->maxBytes()) {
            throw ValidationException::withMessages(['file' => 'The file is empty or larger than the allowed size.']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!str_starts_with($mime, 'text/') && !in_array($mime, ['application/xml', 'application/csv'], true)) {
            throw ValidationException::withMessages(['file' => 'Only XML (CAMT.053) and CSV files are accepted.']);
        }

        return (string) file_get_contents($tmp);
    }
}
