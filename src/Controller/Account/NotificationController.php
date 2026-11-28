<?php

declare(strict_types=1);

namespace Nebrix\Controller\Account;

use Nebrix\Discord\DiscordService;
use Nebrix\Http\Controller;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Notification\NotificationPreferences;
use Nebrix\Notification\NotificationRouter;

/**
 * Account → Notifications: which events the customer receives by e-mail
 * and, with a linked Discord account, as Discord direct message.
 * Mandatory e-mails (invoices, security and account notices, contract
 * declarations) cannot be switched off.
 */
final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationPreferences $preferences,
        private readonly NotificationRouter $router,
        private readonly DiscordService $discord,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $this->user($request);

        return $this->render($request, 'account/notifications.twig', [
            'events' => NotificationPreferences::EVENTS,
            'choices' => $this->preferences->forUser($user->id),
            'discord_available' => $this->router->directMessagesAvailable(),
            'discord_linked' => $this->discord->link($user->id) !== null,
        ]);
    }

    public function update(Request $request): Response
    {
        $user = $this->user($request);
        $data = $this->validate($request, ['email' => 'nullable|array', 'discord' => 'nullable|array']);
        $events = array_keys(NotificationPreferences::EVENTS);
        $email = array_values(array_intersect($events, array_filter((array) ($data['email'] ?? []), 'is_string')));
        $discord = $this->router->directMessagesAvailable() && $this->discord->link($user->id) !== null
            ? array_values(array_intersect($events, array_filter((array) ($data['discord'] ?? []), 'is_string')))
            : [];
        $this->preferences->save($user->id, $email, $discord);
        $this->audit($request, 'account.notifications_updated', 'user', $user->id, ['email' => $email, 'discord' => $discord]);
        $this->flash($request, 'success', 'Your notification settings have been saved.');

        return $this->redirect('/account/notifications');
    }
}
