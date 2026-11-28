<?php

declare(strict_types=1);

/*
 * Support area: help center, ticket attachments and e-mail replies, the
 * optional AI assistant, notifications and status page subscriptions.
 * Shown in Admin → Settings → System (groups support, assistant, notifications).
 */
return [
    // ----------------------------------------------------------------- notifications
    'notifications.discord_dm' => ['group' => 'notifications', 'type' => 'bool', 'default' => false, 'label' => 'Offer Discord direct messages to customers with a linked Discord account', 'help' => 'Sent by the bot configured under Discord. Customers choose the events themselves; Discord only delivers them while the customer shares the server with the bot and allows direct messages.'],
];
