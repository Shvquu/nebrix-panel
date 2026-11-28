<?php

declare(strict_types=1);

/*
 * Support area: help center, ticket attachments and e-mail replies, the
 * optional AI assistant, notifications and status page subscriptions.
 * Shown in Admin → Settings → System (groups support, assistant, notifications).
 */
return [
    // ------------------------------------------------------- help center & tickets
    'support.help_center_enabled' => ['group' => 'support', 'type' => 'bool', 'default' => true, 'label' => 'Enable the public help center (/help)'],
    'support.ticket_suggestions' => ['group' => 'support', 'type' => 'bool', 'default' => true, 'label' => 'Suggest help articles while customers write a ticket subject'],
    'support.attachments_enabled' => ['group' => 'support', 'type' => 'bool', 'default' => true, 'label' => 'Allow attachments in tickets', 'help' => 'Images (PNG, JPEG, GIF, WebP), PDF and plain text/log files, checked by their content. Stored under storage/attachments.'],
    'support.attachment_max_mb' => ['group' => 'support', 'type' => 'int', 'default' => 5, 'label' => 'Maximum size per attachment (MB)', 'rules' => 'required|int|min:1|max:25'],
    'support.attachment_max_files' => ['group' => 'support', 'type' => 'int', 'default' => 3, 'label' => 'Maximum attachments per message', 'rules' => 'required|int|min:1|max:10'],
    'support.ratings_enabled' => ['group' => 'support', 'type' => 'bool', 'default' => true, 'label' => 'Ask customers to rate closed tickets'],
    'support.email_replies_enabled' => ['group' => 'support', 'type' => 'bool', 'default' => false, 'label' => 'Accept e-mail replies to ticket notifications', 'help' => 'Notifications get a personal Reply-To address. Your mail provider must forward replies as JSON to /webhooks/inbound-mail, signed with the shared secret (see the documentation).'],
    'support.reply_local_part' => ['group' => 'support', 'type' => 'string', 'default' => 'support', 'label' => 'Local part of the reply address', 'help' => 'Replies go to <local part>+<ticket>.<signature>@<domain>.', 'rules' => 'required|string|max:30|regex:/^[a-z0-9][a-z0-9._-]{0,29}$/'],
    'support.reply_domain' => ['group' => 'support', 'type' => 'string', 'default' => '', 'label' => 'Domain of the reply address', 'rules' => 'nullable|string|max:150|regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+)?$/'],
    'support.inbound_secret' => ['group' => 'support', 'type' => 'string', 'default' => null, 'label' => 'Shared secret for the inbound e-mail webhook', 'secret' => true, 'help' => 'At least 32 characters. Used to verify the X-Nebrix-Signature header (HMAC-SHA256).', 'rules' => 'nullable|string|min:32|max:200'],

    // ------------------------------------------------------------------ AI assistant
    'assistant.enabled' => ['group' => 'assistant', 'type' => 'bool', 'default' => false, 'label' => 'Enable the AI assistant in the help center (signed-in customers)', 'help' => 'Answers questions from the published help articles through the Claude API (Anthropic). Only the question and the matching articles are sent – no customer data. Costs are billed by Anthropic per request.'],
    'assistant.api_key' => ['group' => 'assistant', 'type' => 'string', 'default' => null, 'label' => 'Anthropic API key', 'secret' => true, 'rules' => 'nullable|string|max:300|regex:/^[A-Za-z0-9_-]+$/'],
    'assistant.model' => ['group' => 'assistant', 'type' => 'string', 'default' => 'claude-opus-5-5', 'label' => 'Model ID', 'rules' => 'required|string|max:80|regex:/^claude-[a-z0-9-]{1,70}$/'],
    'assistant.effort' => ['group' => 'assistant', 'type' => 'select', 'default' => 'low', 'label' => 'Effort', 'options' => ['low' => 'Low (fast, inexpensive)', 'medium' => 'Medium', 'high' => 'High'], 'help' => 'Higher effort can improve answers but takes longer and uses more tokens.'],
    'assistant.max_tokens' => ['group' => 'assistant', 'type' => 'int', 'default' => 2000, 'label' => 'Maximum tokens per answer', 'rules' => 'required|int|min:256|max:8000'],
    'assistant.max_question_chars' => ['group' => 'assistant', 'type' => 'int', 'default' => 500, 'label' => 'Maximum question length (characters)', 'rules' => 'required|int|min:50|max:2000'],
    'assistant.hourly_limit' => ['group' => 'assistant', 'type' => 'int', 'default' => 10, 'label' => 'Questions per customer and hour', 'rules' => 'required|int|min:1|max:100'],
    'assistant.daily_limit' => ['group' => 'assistant', 'type' => 'int', 'default' => 30, 'label' => 'Questions per customer and day', 'rules' => 'required|int|min:1|max:500'],
    'assistant.global_daily_limit' => ['group' => 'assistant', 'type' => 'int', 'default' => 500, 'label' => 'Questions per day in total (cost cap)', 'rules' => 'required|int|min:1|max:100000'],

    // ----------------------------------------------------------------- notifications
    'notifications.discord_dm' => ['group' => 'notifications', 'type' => 'bool', 'default' => false, 'label' => 'Offer Discord direct messages to customers with a linked Discord account', 'help' => 'Sent by the bot configured under Discord. Customers choose the events themselves; Discord only delivers them while the customer shares the server with the bot and allows direct messages.'],
    'notifications.staff_webhook_url' => ['group' => 'notifications', 'type' => 'string', 'default' => null, 'label' => 'Discord channel webhook for staff notifications', 'secret' => true, 'help' => 'https://discord.com/api/webhooks/… – posts new orders, new tickets and failed payments or provisioning (numbers and links only, no customer data).', 'rules' => 'nullable|string|max:300|regex:#^https://discord\.com/api/webhooks/\d{17,20}/[A-Za-z0-9_-]{30,100}$#'],
    'notifications.staff_events' => ['group' => 'notifications', 'type' => 'list', 'default' => ['order', 'ticket', 'payment_failed', 'provisioning_failed'], 'label' => 'Events posted to the staff channel', 'options' => ['order' => 'New order', 'ticket' => 'New ticket', 'payment_failed' => 'Failed payment', 'provisioning_failed' => 'Failed provisioning']],
    'notifications.status_subscriptions' => ['group' => 'notifications', 'type' => 'bool', 'default' => true, 'label' => 'Visitors can subscribe to status page updates by e-mail', 'help' => 'Double opt-in; every e-mail contains a one-click unsubscribe link.'],
];
