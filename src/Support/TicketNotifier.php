<?php

declare(strict_types=1);

namespace Nebrix\Support;

use Nebrix\Auth\User;
use Nebrix\Core\Config;
use Nebrix\Mail\Mailer;
use Nebrix\Repository\UserRepository;
use Nebrix\Settings\SettingsRepository;

/**
 * E-mails about tickets: staff replies and closing (to the customer, with
 * the personal reply address when e-mail replies are enabled) and
 * assignments (to the staff member).
 */
class TicketNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly UserRepository $users,
        private readonly Config $config,
        private readonly ReplyAddress $replyAddress,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $ticket
     */
    public function staffReplied(array $ticket): void
    {
        $customer = $this->users->find((int) $ticket['user_id']);
        if ($customer === null) {
            return;
        }
        $id = (int) $ticket['id'];
        $this->mailer->queue($customer->email, $customer->displayName(), 'ticket_reply', [
            'name' => $customer->displayName(),
            'ticket_subject' => (string) $ticket['subject'],
            'ticket_url' => $this->url('/tickets/' . $id),
        ], null, [], null, $this->headers($id));
    }

    /**
     * @param array<string, mixed> $ticket
     */
    public function closed(array $ticket): void
    {
        $customer = $this->users->find((int) $ticket['user_id']);
        if ($customer === null) {
            return;
        }
        $id = (int) $ticket['id'];
        $this->mailer->queue($customer->email, $customer->displayName(), 'ticket_closed', [
            'name' => $customer->displayName(),
            'ticket_subject' => (string) $ticket['subject'],
            'ticket_url' => $this->url('/tickets/' . $id),
            'rating_url' => $this->url('/tickets/' . $id . ($this->settings->bool('support.ratings_enabled') ? '#rating' : '')),
        ], null, [], null, $this->headers($id));
    }

    /**
     * @param array<string, mixed> $ticket
     */
    public function assigned(array $ticket, User $assignee, User $actor): void
    {
        if ($assignee->id === $actor->id) {
            return;
        }
        $id = (int) $ticket['id'];
        $this->mailer->queue($assignee->email, $assignee->displayName(), 'ticket_assigned', [
            'name' => $assignee->displayName(),
            'ticket_id' => $id,
            'ticket_subject' => (string) $ticket['subject'],
            'priority' => (string) $ticket['priority'],
            'assigned_by' => $actor->displayName(),
            'ticket_url' => $this->url('/admin/tickets/' . $id),
        ]);
    }

    /**
     * @return array{reply_to?: string}
     */
    private function headers(int $ticketId): array
    {
        $address = $this->replyAddress->forTicket($ticketId);

        return $address !== null ? ['reply_to' => $address] : [];
    }

    private function url(string $path): string
    {
        return rtrim($this->config->string('app.url'), '/') . $path;
    }
}
