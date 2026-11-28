<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Marketing\Newsletter;
use Nebrix\Marketing\NewsletterCampaigns;
use Nebrix\Security\RateLimiter;
use Nebrix\Security\ValidationException;
use Nebrix\Security\Validator;
use RuntimeException;
use Twig\Markup;

/**
 * Newsletter administration (Marketing → Newsletter): subscribers (search,
 * CSV export with newsletter.export, deletion on request) and campaigns
 * (Markdown, preview, test to the own address, sending through the job
 * queue). Viewing needs newsletter.view, changes newsletter.manage; every
 * change and every export is audit-logged.
 */
final class NewsletterController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly Newsletter $newsletter,
        private readonly NewsletterCampaigns $campaigns,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function index(Request $request): Response
    {
        $campaigns = $this->campaigns->all();
        foreach ($campaigns as $key => $campaign) {
            $campaigns[$key]['mails'] = $campaign['status'] !== NewsletterCampaigns::DRAFT ? $this->campaigns->mailStats((int) $campaign['id']) : null;
        }

        return $this->render($request, 'admin/marketing/newsletter/index.twig', [
            'campaigns' => $campaigns,
            'counts' => $this->newsletter->counts(),
            'enabled' => $this->newsletter->enabled(),
        ]);
    }

    public function subscribers(Request $request): Response
    {
        $page = $this->page($request);
        $search = $request->string('search');
        $status = $request->string('status');
        $result = $this->newsletter->search($search, $status, $page, self::PER_PAGE);

        return $this->render($request, 'admin/marketing/newsletter/subscribers.twig', [
            'subscribers' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'search' => $search,
            'status' => $status,
            'counts' => $this->newsletter->counts(),
        ]);
    }

    /** CSV export (newsletter.export) – the proof of consent of every address; audit-logged. */
    public function export(Request $request): Response
    {
        $this->authorize($request, Permission::NEWSLETTER_EXPORT);
        $search = $request->string('search');
        $status = $request->string('status');
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new RuntimeException('The export could not be created.');
        }
        fputcsv($out, ['email', 'status', 'language', 'source', 'requested_at', 'requested_ip', 'confirmed_at', 'confirmed_ip', 'unsubscribed_at', 'consent_text'], ',', '"', '');
        $count = 0;
        foreach ($this->newsletter->export($search, $status) as $row) {
            fputcsv($out, array_map(self::cell(...), [$row['email'], $row['status'], $row['locale'], $row['source'], $row['requested_at'], $row['requested_ip'], $row['confirmed_at'], $row['confirmed_ip'], $row['unsubscribed_at'], $row['consent_text']]), ',', '"', '');
            $count++;
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->audit($request, 'newsletter.exported', 'newsletter', null, ['rows' => $count, 'status' => $status !== '' ? $status : null, 'search' => $search !== '']);

        return new Response("\u{FEFF}" . $csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="newsletter-subscribers.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function deleteSubscriber(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::NEWSLETTER_MANAGE);
        $this->newsletter->find($id) ?? throw HttpException::notFound();
        $this->newsletter->delete($id);
        // The address itself is not written to the audit log (it was deleted on request).
        $this->audit($request, 'newsletter.subscriber_deleted', 'newsletter_subscriber', $id);
        $this->flash($request, 'success', 'The subscriber has been deleted.');

        return $this->redirect('/admin/newsletter/subscribers');
    }

    public function create(Request $request): Response
    {
        $this->authorize($request, Permission::NEWSLETTER_MANAGE);

        return $this->form($request, null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->campaigns->find($id) ?? throw HttpException::notFound());
    }

    public function store(Request $request): Response
    {
        $this->authorize($request, Permission::NEWSLETTER_MANAGE);
        $id = $this->campaigns->save(null, $this->input($request), $this->user($request)->id);
        $this->audit($request, 'newsletter.campaign_created', 'newsletter_campaign', $id);
        $this->flash($request, 'success', 'Campaign saved.');

        return $this->redirect('/admin/newsletter/campaigns/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::NEWSLETTER_MANAGE);
        $this->campaigns->find($id) ?? throw HttpException::notFound();
        try {
            $this->campaigns->save($id, $this->input($request), $this->user($request)->id);
        } catch (RuntimeException $exception) {
            if ($exception instanceof \PDOException) {
                throw $exception;
            }
            $this->flash($request, 'error', 'Campaigns that are being sent or have been sent cannot be changed.');

            return $this->redirect('/admin/newsletter/campaigns/' . $id);
        }
        $this->audit($request, 'newsletter.campaign_updated', 'newsletter_campaign', $id);
        $this->flash($request, 'success', 'Campaign saved.');

        return $this->redirect('/admin/newsletter/campaigns/' . $id);
    }

    /** Sends the campaign once to the staff member's own address. */
    public function test(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::NEWSLETTER_MANAGE);
        $this->campaigns->find($id) ?? throw HttpException::notFound();
        $user = $this->user($request);
        if (!$this->limiter->attempt('newsletter-test:' . $user->id, 10, 3600)) {
            throw HttpException::tooManyRequests('Too many test e-mails. Please try again later.');
        }
        $this->campaigns->sendTest($id, $user->email, $user->displayName(), (string) ($user->attributes['language'] ?? '') ?: 'en');
        $this->audit($request, 'newsletter.campaign_tested', 'newsletter_campaign', $id);
        $this->flash($request, 'success', 'The test e-mail has been queued to your own address.');

        return $this->redirect('/admin/newsletter/campaigns/' . $id);
    }

    public function send(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::NEWSLETTER_MANAGE);
        $this->campaigns->find($id) ?? throw HttpException::notFound();
        if ($request->string('confirm') !== 'SEND') {
            throw ValidationException::withMessages(['confirm' => 'Type SEND to confirm.']);
        }
        try {
            $recipients = $this->campaigns->start($id, $this->user($request)->id);
        } catch (RuntimeException $exception) {
            if ($exception instanceof \PDOException) {
                throw $exception;
            }
            $this->flash($request, 'error', 'This campaign has already been sent.');

            return $this->redirect('/admin/newsletter/campaigns/' . $id);
        }
        $this->audit($request, 'newsletter.campaign_sent', 'newsletter_campaign', $id, ['recipients' => $recipients]);
        $this->flash($request, 'success', 'The campaign is being sent in the background.');

        return $this->redirect('/admin/newsletter/campaigns/' . $id);
    }

    public function cancel(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::NEWSLETTER_MANAGE);
        $this->campaigns->find($id) ?? throw HttpException::notFound();
        if ($this->campaigns->cancel($id)) {
            $this->audit($request, 'newsletter.campaign_cancelled', 'newsletter_campaign', $id);
            $this->flash($request, 'success', 'Sending has been stopped.');
        }

        return $this->redirect('/admin/newsletter/campaigns/' . $id);
    }

    public function delete(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::NEWSLETTER_MANAGE);
        $this->campaigns->find($id) ?? throw HttpException::notFound();
        try {
            $this->campaigns->delete($id);
        } catch (RuntimeException $exception) {
            if ($exception instanceof \PDOException) {
                throw $exception;
            }
            $this->flash($request, 'error', 'Only drafts can be deleted.');

            return $this->redirect('/admin/newsletter/campaigns/' . $id);
        }
        $this->audit($request, 'newsletter.campaign_deleted', 'newsletter_campaign', $id);
        $this->flash($request, 'success', 'Campaign deleted.');

        return $this->redirect('/admin/newsletter');
    }

    /**
     * @param array<string, mixed>|null $campaign
     */
    private function form(Request $request, ?array $campaign): Response
    {
        return $this->render($request, 'admin/marketing/newsletter/campaign.twig', [
            'campaign' => $campaign,
            'preview' => $campaign !== null ? new Markup($this->campaigns->previewHtml((string) $campaign['body']), 'UTF-8') : null,
            'audiences' => ['all' => 'All confirmed subscribers', 'de' => 'German-speaking subscribers', 'en' => 'English-speaking subscribers'],
            'audience_size' => $campaign !== null ? $this->campaigns->audienceSize((string) $campaign['audience']) : null,
            'mails' => $campaign !== null && $campaign['status'] !== NewsletterCampaigns::DRAFT ? $this->campaigns->mailStats((int) $campaign['id']) : null,
            'enabled' => $this->newsletter->enabled(),
        ]);
    }

    /**
     * @return array{subject: string, body: string, audience: string}
     */
    private function input(Request $request): array
    {
        $data = $this->validate($request, [
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:100000',
            'audience' => 'required|in:all,de,en',
        ], ['subject' => 'Subject', 'body' => 'Text']);
        if (preg_match('/[\r\n]/', (string) $data['subject']) === 1) {
            throw new ValidationException(['subject' => ['The subject must be a single line.']], Validator::safeInput($request->all()));
        }

        return ['subject' => trim((string) $data['subject']), 'body' => (string) $data['body'], 'audience' => (string) $data['audience']];
    }

    /** Neutralises spreadsheet formulas (CSV injection). */
    private static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
