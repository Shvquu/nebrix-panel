<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Repository\UserRepository;
use Nebrix\Security\ValidationException;
use Nebrix\Support\CannedResponses;
use Nebrix\Support\TicketAttachments;
use Nebrix\Support\TicketNotifier;
use Nebrix\Support\TicketRatings;
use Nebrix\Support\TicketRepository;
use RuntimeException;

final class TicketController extends Controller
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly UserRepository $users,
        private readonly TicketAttachments $attachments,
        private readonly CannedResponses $canned,
        private readonly TicketRatings $ratings,
        private readonly TicketNotifier $notifier,
    ) {
    }

    public function index(Request $request): Response
    {
        $status = $request->string('status', 'active');
        $filters = [
            'status' => in_array($status, [...TicketRepository::STATUSES, 'active', ''], true) ? $status : 'active',
            'search' => mb_substr($request->string('search'), 0, 100),
        ];
        if ($request->string('mine') === '1') {
            $filters['assigned'] = $this->user($request)->id;
        }
        $page = $this->page($request);

        return $this->render($request, 'admin/tickets/index.twig', [
            'result' => $this->tickets->paginate($filters, $page),
            'filters' => $filters,
            'page' => $page,
            'mine' => $request->string('mine') === '1',
            'ratings' => $this->ratings->summary(),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $ticket = $this->tickets->find($id) ?? throw HttpException::notFound();
        $staff = [];
        foreach ($this->users->paginate(['staff' => true, 'status' => 'active'], 1, 200)['items'] as $member) {
            $staff[(int) $member['id']] = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?: (string) $member['email'];
        }
        $me = $this->user($request);
        $canned = [];
        foreach ($this->canned->all() as $response) {
            // Placeholders are filled in on the server; the browser only inserts the text.
            $canned[] = ['id' => (int) $response['id'], 'title' => (string) $response['title'], 'body' => CannedResponses::fill((string) $response['body'], $ticket, $me->displayName())];
        }

        return $this->render($request, 'admin/tickets/show.twig', [
            'ticket' => $ticket,
            'messages' => $this->tickets->messages($id),
            'staff' => $staff,
            'attachments' => $this->attachments->forTicket($id),
            'uploads' => ['enabled' => $this->attachments->enabled(), 'max_files' => $this->attachments->maxFiles(), 'max_mb' => intdiv($this->attachments->maxBytes(), 1024 * 1024)],
            'canned' => $canned,
            'rating' => $this->ratings->forTicket($id),
        ]);
    }

    public function reply(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::TICKETS_REPLY);
        $ticket = $this->tickets->find($id) ?? throw HttpException::notFound();
        $data = $this->validate($request, ['message' => 'required|string|min:2|max:20000']);
        try {
            $files = $this->attachments->validate(TicketAttachments::uploadedFiles($request->file('attachments')));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['attachments' => $exception->getMessage()]);
        }

        $staff = $this->user($request);
        $message = CannedResponses::fill((string) $data['message'], $ticket, $staff->displayName());
        $messageId = $this->tickets->addMessage($id, $staff->id, true, $message);
        if ($files !== []) {
            $this->attachments->store($id, $messageId, $staff->id, true, $files);
        }
        $this->audit($request, 'ticket.replied', 'ticket', $id, $files !== [] ? ['attachments' => count($files)] : []);
        $this->notifier->staffReplied($ticket);
        $this->flash($request, 'success', 'Reply sent.');

        return $this->redirect('/admin/tickets/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::TICKETS_MANAGE);
        $ticket = $this->tickets->find($id) ?? throw HttpException::notFound();
        $data = $this->validate($request, [
            'status' => 'required|in:' . implode(',', TicketRepository::STATUSES),
            'priority' => 'required|in:' . implode(',', TicketRepository::PRIORITIES),
            'assigned_to' => 'nullable|int|min:1',
        ]);

        $assignee = null;
        if ($data['assigned_to'] !== null) {
            $assignee = $this->users->find((int) $data['assigned_to']);
            if ($assignee === null || !$assignee->isStaff()) {
                throw HttpException::badRequest();
            }
        }

        $this->tickets->setStatus($id, (string) $data['status']);
        $this->tickets->setPriority($id, (string) $data['priority']);
        $this->tickets->assign($id, $data['assigned_to'] !== null ? (int) $data['assigned_to'] : null);
        $this->audit($request, 'ticket.updated', 'ticket', $id, ['status' => $data['status'], 'priority' => $data['priority'], 'assigned_to' => $data['assigned_to']]);

        $updated = $this->tickets->find($id) ?? $ticket;
        if ($assignee !== null && (int) ($ticket['assigned_to'] ?? 0) !== $assignee->id) {
            $this->notifier->assigned($updated, $assignee, $this->user($request));
        }
        if ($data['status'] === 'closed' && $ticket['status'] !== 'closed') {
            $this->notifier->closed($updated);
        }
        $this->flash($request, 'success', 'Ticket updated.');

        return $this->redirect('/admin/tickets/' . $id);
    }

    public function attachment(Request $request, int $id, int $attachment): Response
    {
        $this->authorize($request, Permission::TICKETS_VIEW);
        $this->tickets->find($id) ?? throw HttpException::notFound();
        $row = $this->attachments->find($id, $attachment) ?? throw HttpException::notFound();

        return $this->attachments->download($row) ?? throw HttpException::notFound();
    }
}
