<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\RateLimiter;
use Nebrix\Security\ValidationException;
use Nebrix\Service\ServiceRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Support\TicketAttachments;
use Nebrix\Support\TicketRatings;
use Nebrix\Support\TicketRepository;
use RuntimeException;

final class TicketController extends Controller
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly ServiceRepository $services,
        private readonly RateLimiter $limiter,
        private readonly SettingsRepository $settings,
        private readonly TicketAttachments $attachments,
        private readonly TicketRatings $ratings,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->ensureEnabled();

        return $this->render($request, 'customer/tickets/index.twig', ['tickets' => $this->tickets->forUser($this->user($request)->id)]);
    }

    public function create(Request $request): Response
    {
        $this->ensureEnabled();
        $services = [];
        foreach ($this->services->forUser($this->user($request)->id) as $service) {
            $services[$service->id] = $service->name;
        }
        $preselect = $request->query('service');

        return $this->render($request, 'customer/tickets/create.twig', [
            'services' => $services,
            'preselect' => is_string($preselect) && ctype_digit($preselect) ? (int) $preselect : null,
            // Prefilled e.g. from the help center assistant ("Create ticket").
            'prefill' => ['subject' => mb_substr($request->string('subject'), 0, 200), 'message' => mb_substr($request->string('message'), 0, 5000)],
            'uploads' => $this->uploadLimits(),
            'suggestions' => $this->settings->bool('support.help_center_enabled') && $this->settings->bool('support.ticket_suggestions'),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        $data = $this->validate($request, [
            'subject' => 'required|string|min:3|max:200',
            'message' => 'required|string|min:10|max:20000',
            'service_id' => 'nullable|int|min:1',
            'priority' => 'required|in:low,normal,high',
        ]);

        // A customer can only reference their own services.
        if ($data['service_id'] !== null && $this->services->findForUser((int) $data['service_id'], $user->id) === null) {
            throw ValidationException::withMessages(['service_id' => 'Please choose one of your services.']);
        }

        $files = $this->checkedUploads($request);

        $key = 'ticket-create:' . $user->id;
        if (!$this->limiter->attempt($key, 5, 3600)) {
            throw HttpException::tooManyRequests('You have opened several tickets in a short time. Please wait before opening another one.');
        }

        $id = $this->tickets->create($user->id, (string) $data['subject'], (string) $data['message'], $data['service_id'] !== null ? (int) $data['service_id'] : null, (string) $data['priority']);
        if ($files !== []) {
            $this->attachments->store($id, $this->tickets->firstMessageId($id), $user->id, false, $files);
        }
        $this->flash($request, 'success', 'Your request has been submitted. We will get back to you as soon as possible.');

        return $this->redirect('/tickets/' . $id);
    }

    public function show(Request $request, int $id): Response
    {
        $this->ensureEnabled();
        $ticket = $this->find($request, $id);

        return $this->render($request, 'customer/tickets/show.twig', [
            'ticket' => $ticket,
            'messages' => $this->tickets->messages($id),
            'attachments' => $this->attachments->forTicket($id),
            'uploads' => $this->uploadLimits(),
            'rating' => $this->ratings->forTicket($id),
            'ratings_enabled' => $this->settings->bool('support.ratings_enabled'),
        ]);
    }

    public function reply(Request $request, int $id): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        $ticket = $this->find($request, $id);
        $data = $this->validate($request, ['message' => 'required|string|min:2|max:20000']);
        $files = $this->checkedUploads($request);

        $key = 'ticket-reply:' . $user->id;
        if (!$this->limiter->attempt($key, 30, 3600)) {
            throw HttpException::tooManyRequests();
        }

        $messageId = $this->tickets->addMessage((int) $ticket['id'], $user->id, false, (string) $data['message']);
        if ($files !== []) {
            $this->attachments->store((int) $ticket['id'], $messageId, $user->id, false, $files);
        }
        $this->flash($request, 'success', 'Your reply has been sent.');

        return $this->redirect('/tickets/' . $id);
    }

    public function close(Request $request, int $id): Response
    {
        $this->ensureEnabled();
        $this->find($request, $id);
        $this->tickets->setStatus($id, 'closed');
        $this->flash($request, 'success', 'The ticket has been closed.');

        return $this->redirect('/tickets/' . $id);
    }

    /** Download of an attachment of one of the customer's own tickets. */
    public function attachment(Request $request, int $id, int $attachment): Response
    {
        $this->ensureEnabled();
        $ticket = $this->find($request, $id);
        $row = $this->attachments->find((int) $ticket['id'], $attachment) ?? throw HttpException::notFound();

        return $this->attachments->download($row) ?? throw HttpException::notFound();
    }

    /** Satisfaction rating of a closed ticket: once, only by its owner. */
    public function rate(Request $request, int $id): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        $ticket = $this->find($request, $id);
        if (!$this->settings->bool('support.ratings_enabled') || $ticket['status'] !== 'closed') {
            throw HttpException::notFound();
        }
        $data = $this->validate($request, ['rating' => 'required|int|min:1|max:5', 'comment' => 'nullable|string|max:2000']);
        if (!$this->limiter->attempt('ticket-rating:' . $user->id, 20, 3600)) {
            throw HttpException::tooManyRequests();
        }
        $stored = $this->ratings->rate((int) $ticket['id'], $user->id, (int) $data['rating'], $data['comment'] !== null ? (string) $data['comment'] : null);
        $this->flash($request, $stored ? 'success' : 'info', $stored ? 'Thank you for rating our support.' : 'You have already rated this ticket.');

        return $this->redirect('/tickets/' . $id);
    }

    /**
     * @return array{enabled: bool, max_files: int, max_mb: int}
     */
    private function uploadLimits(): array
    {
        return ['enabled' => $this->attachments->enabled(), 'max_files' => $this->attachments->maxFiles(), 'max_mb' => intdiv($this->attachments->maxBytes(), 1024 * 1024)];
    }

    /**
     * @return list<array{name: string, tmp_name: string, mime: string, size: int}>
     */
    private function checkedUploads(Request $request): array
    {
        try {
            return $this->attachments->validate(TicketAttachments::uploadedFiles($request->file('attachments')));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['attachments' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function find(Request $request, int $id): array
    {
        return $this->tickets->findForUser($id, $this->user($request)->id) ?? throw HttpException::notFound();
    }

    private function ensureEnabled(): void
    {
        if (!$this->settings->bool('system.tickets_enabled')) {
            throw HttpException::notFound();
        }
    }
}
