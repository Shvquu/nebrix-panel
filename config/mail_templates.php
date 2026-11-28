<?php

declare(strict_types=1);

/*
 * Default e-mail templates. Administrators can override subject and body in
 * Admin → E-mail templates; "Reset to default" restores these versions.
 *
 * Placeholders use {{ name }}. Values are HTML-escaped automatically in the
 * HTML body. Always available: company_name, support_email, app_url.
 */
return [
    'account_registration' => [
        'name' => 'Account registration',
        'variables' => ['name', 'email', 'login_url'],
        'subject' => 'Welcome to {{ company_name }}',
        'html' => '<p>Hello {{ name }},</p><p>thank you for creating an account with {{ company_name }}. You can sign in at any time:</p><p><a class="button" href="{{ login_url }}">Sign in</a></p><p>If you did not create this account, please contact {{ support_email }}.</p>',
    ],
    'email_verification' => [
        'name' => 'E-mail verification',
        'variables' => ['name', 'verification_url', 'expires_hours'],
        'subject' => 'Please confirm your e-mail address',
        'html' => '<p>Hello {{ name }},</p><p>please confirm your e-mail address to activate your account:</p><p><a class="button" href="{{ verification_url }}">Confirm e-mail address</a></p><p>The link is valid for {{ expires_hours }} hours. If you did not register, you can ignore this e-mail.</p>',
    ],
    'password_reset' => [
        'name' => 'Password reset',
        'variables' => ['name', 'reset_url', 'expires_minutes', 'ip'],
        'subject' => 'Reset your password',
        'html' => '<p>Hello {{ name }},</p><p>we received a request to reset your password (from IP address {{ ip }}). Use the button below to choose a new password:</p><p><a class="button" href="{{ reset_url }}">Reset password</a></p><p>The link is valid for {{ expires_minutes }} minutes and can be used once. If you did not request this, you can ignore this e-mail – your password stays unchanged.</p>',
    ],
    'api_token_created' => [
        'name' => 'API token created',
        'variables' => ['name', 'token_name', 'scopes', 'expires', 'ip', 'tokens_url'],
        'subject' => 'A new API token was created for your account',
        'html' => '<p>Hello {{ name }},</p><p>a new API token "{{ token_name }}" was created for your {{ company_name }} account (IP address {{ ip }}). Permissions: {{ scopes }}. The token expires on {{ expires }}.</p><p>If you did not create this token, revoke it immediately, change your password and contact {{ support_email }}.</p><p><a class="button" href="{{ tokens_url }}">Manage API tokens</a></p>',
    ],
    'password_changed' => [
        'name' => 'Password changed',
        'variables' => ['name', 'ip', 'time'],
        'subject' => 'Your password was changed',
        'html' => '<p>Hello {{ name }},</p><p>the password of your {{ company_name }} account was changed on {{ time }} (IP address {{ ip }}). All other sessions were signed out and all API tokens were revoked.</p><p>If this was not you, reset your password immediately and contact {{ support_email }}.</p>',
    ],
    'account_locked' => [
        'name' => 'Sign-in locked after failed attempts',
        'variables' => ['name', 'attempts', 'minutes', 'ip', 'time', 'security_url'],
        'subject' => 'Sign-in to your account was locked',
        'html' => '<p>Hello {{ name }},</p><p>after {{ attempts }} failed sign-in attempts, sign-in to your {{ company_name }} account is locked for {{ minutes }} minute(s) (last attempt on {{ time }} from IP address {{ ip }}).</p><p>If this was you, wait and try again or reset your password. If it was not you, someone tried to guess your password: your account is safe as long as the password was not entered correctly. Use a strong, unique password and enable two-factor authentication.</p><p><a class="button" href="{{ security_url }}">Security settings</a></p><p>Questions? Contact {{ support_email }}.</p>',
    ],
    'two_factor_failed' => [
        'name' => 'Correct password, wrong two-factor code',
        'variables' => ['name', 'ip', 'time', 'security_url'],
        'subject' => 'Sign-in attempt with your password',
        'html' => '<p>Hello {{ name }},</p><p>on {{ time }} someone signed in to your {{ company_name }} account with the <strong>correct password</strong> from IP address {{ ip }}, but entered a wrong two-factor code. Two-factor authentication stopped the sign-in.</p><p>If this was not you, your password is known to someone else: change it immediately (this also signs out all sessions and revokes API tokens).</p><p><a class="button" href="{{ security_url }}">Change password</a></p><p>Questions? Contact {{ support_email }}.</p>',
    ],
    'order_confirmation' => [
        'name' => 'Order confirmation',
        'variables' => ['name', 'order_number', 'order_total', 'invoice_url'],
        'subject' => 'Order {{ order_number }} received',
        'html' => '<p>Hello {{ name }},</p><p>thank you for your order <strong>{{ order_number }}</strong> ({{ order_total }}).</p><p>Your services will be set up automatically as soon as the payment has been confirmed.</p><p><a class="button" href="{{ invoice_url }}">View and pay invoice</a></p>',
    ],
    'invoice_created' => [
        'name' => 'Invoice created',
        'variables' => ['name', 'invoice_number', 'invoice_total', 'due_date', 'invoice_url'],
        'subject' => 'Invoice {{ invoice_number }}',
        'html' => '<p>Hello {{ name }},</p><p>invoice <strong>{{ invoice_number }}</strong> over {{ invoice_total }} is due on {{ due_date }}.</p><p><a class="button" href="{{ invoice_url }}">View and pay invoice</a></p>',
    ],
    'payment_confirmation' => [
        'name' => 'Payment confirmation',
        'variables' => ['name', 'invoice_number', 'amount', 'gateway'],
        'subject' => 'Payment received for invoice {{ invoice_number }}',
        'html' => '<p>Hello {{ name }},</p><p>we have received your payment of {{ amount }} via {{ gateway }} for invoice {{ invoice_number }}. Thank you!</p>',
    ],
    'service_provisioned' => [
        'name' => 'Service provisioned',
        'variables' => ['name', 'service_name', 'service_url'],
        'subject' => 'Your service "{{ service_name }}" is ready',
        'html' => '<p>Hello {{ name }},</p><p>your service <strong>{{ service_name }}</strong> has been set up. For security reasons access data is not sent by e-mail; you can view it in your customer area:</p><p><a class="button" href="{{ service_url }}">Open service</a></p>',
    ],
    'service_suspended' => [
        'name' => 'Service suspended',
        'variables' => ['name', 'service_name', 'reason', 'service_url'],
        'subject' => 'Your service "{{ service_name }}" was suspended',
        'html' => '<p>Hello {{ name }},</p><p>your service <strong>{{ service_name }}</strong> was suspended. Reason: {{ reason }}</p><p>Pay the open invoice to reactivate it automatically.</p><p><a class="button" href="{{ service_url }}">Open service</a></p>',
    ],
    'service_expiring' => [
        'name' => 'Service expiration',
        'variables' => ['name', 'service_name', 'due_date', 'invoice_url'],
        'subject' => 'Your service "{{ service_name }}" is due for renewal',
        'html' => '<p>Hello {{ name }},</p><p>your service <strong>{{ service_name }}</strong> is due for renewal on {{ due_date }}. Please pay the renewal invoice to avoid an interruption. If automatic payment from your credit is enabled and the balance suffices, the invoice is paid automatically.</p><p><a class="button" href="{{ invoice_url }}">Pay renewal invoice</a></p>',
    ],
    'invoice_reminder' => [
        'name' => 'Payment reminder',
        'variables' => ['name', 'invoice_number', 'invoice_total', 'due_date', 'invoice_url'],
        'subject' => 'Payment reminder: invoice {{ invoice_number }}',
        'html' => '<p>Hello {{ name }},</p><p>invoice {{ invoice_number }} over {{ invoice_total }} is due on {{ due_date }} and has not been paid yet. Please pay it in time – services with unpaid renewal invoices are suspended after the due date.</p><p><a class="button" href="{{ invoice_url }}">Pay invoice</a></p><p>If you have already paid, please ignore this e-mail.</p>',
    ],
    'cancellation_received' => [
        'name' => 'Cancellation / withdrawal received',
        'variables' => ['name', 'reference', 'kind', 'contract', 'received_at', 'effective', 'reason'],
        'subject' => 'Confirmation of receipt: {{ kind }} {{ reference }}',
        'html' => '<p>Hello {{ name }},</p><p>we have received your declaration and confirm its receipt:</p><p>Reference: <strong>{{ reference }}</strong><br>Type: {{ kind }}<br>Contract: {{ contract }}<br>Received: {{ received_at }}<br>Requested end: {{ effective }}<br>Reason: {{ reason }}</p><p>We will confirm the exact end date separately. If you did not send this declaration, please contact {{ support_email }} immediately.</p>',
    ],
    'cancellation_confirmed' => [
        'name' => 'Cancellation confirmed',
        'variables' => ['name', 'reference', 'service_name', 'effective_date', 'service_url'],
        'subject' => 'Your cancellation of "{{ service_name }}" takes effect on {{ effective_date }}',
        'html' => '<p>Hello {{ name }},</p><p>your service <strong>{{ service_name }}</strong> ends on <strong>{{ effective_date }}</strong> (reference {{ reference }}). No renewal invoices are issued for the time after this date; invoices for the time before it remain payable. Please save your data before that date – the service and its data are deleted when it ends.</p><p>If you want to keep the service, take back the cancellation in your customer area or contact our support before that date.</p><p><a class="button" href="{{ service_url }}">Open service</a></p>',
    ],
    'cancellation_rejected' => [
        'name' => 'Cancellation / withdrawal not accepted',
        'variables' => ['name', 'reference', 'note'],
        'subject' => 'Your declaration {{ reference }}',
        'html' => '<p>Hello {{ name }},</p><p>we could not process your declaration {{ reference }}:</p><p>{{ note }}</p><p>If you have questions, please contact {{ support_email }}.</p>',
    ],
    'invoice_correction' => [
        'name' => 'Cancellation / correction document',
        'variables' => ['name', 'document_number', 'invoice_number', 'amount', 'document_url'],
        'subject' => 'Document {{ document_number }} for invoice {{ invoice_number }}',
        'html' => '<p>Hello {{ name }},</p><p>we have issued the document <strong>{{ document_number }}</strong> over {{ amount }} for invoice {{ invoice_number }} (cancellation or refund). It is attached and available in your customer area.</p><p><a class="button" href="{{ document_url }}">View document</a></p>',
    ],
    'service_package_changed' => [
        'name' => 'Package changed (upgrade/downgrade)',
        'variables' => ['name', 'service_name', 'package', 'price', 'note', 'service_url'],
        'subject' => 'Your service {{ service_name }} now runs with {{ package }}',
        'html' => '<p>Hello {{ name }},</p><p>the package of your service <strong>{{ service_name }}</strong> has been changed to <strong>{{ package }}</strong>. From the next renewal the price is {{ price }} (net) per period.</p><p>{{ note }}</p><p><a class="button" href="{{ service_url }}">Open service</a></p>',
    ],
    'status_maintenance' => [
        'name' => 'Status page: maintenance announcement',
        'variables' => ['name', 'title', 'message', 'window', 'status', 'status_url'],
        'subject' => 'Maintenance: {{ title }}',
        'html' => '<p>Hello {{ name }},</p><p>we are announcing maintenance that may affect your services:</p><p><strong>{{ title }}</strong><br>{{ window }}<br>Status: {{ status }}</p><p>{{ message }}</p><p><a class="button" href="{{ status_url }}">Open status page</a></p>',
    ],
    'status_incident' => [
        'name' => 'Status page: incident',
        'variables' => ['name', 'title', 'message', 'window', 'status', 'status_url'],
        'subject' => 'Incident: {{ title }}',
        'html' => '<p>Hello {{ name }},</p><p>we are currently dealing with an incident that may affect your services:</p><p><strong>{{ title }}</strong><br>Status: {{ status }}</p><p>{{ message }}</p><p>We keep the status page up to date.</p><p><a class="button" href="{{ status_url }}">Open status page</a></p>',
    ],
    'referral_reward' => [
        'name' => 'Refer a friend: reward credited',
        'variables' => ['name', 'amount', 'referrals_url', 'invoices_url'],
        'subject' => 'Thank you for your recommendation – {{ amount }} credit',
        'html' => '<p>Hello {{ name }},</p><p>a customer you referred to {{ company_name }} has become a paying customer. As a thank-you we have added <strong>{{ amount }}</strong> to your account credit. It is used for your next invoices.</p><p><a class="button" href="{{ referrals_url }}">View your referrals</a></p>',
    ],
    'referral_welcome' => [
        'name' => 'Refer a friend: welcome credit',
        'variables' => ['name', 'amount', 'referrals_url', 'invoices_url'],
        'subject' => 'Your welcome credit of {{ amount }}',
        'html' => '<p>Hello {{ name }},</p><p>you joined {{ company_name }} through a recommendation. As a welcome we have added <strong>{{ amount }}</strong> to your account credit. It is used for your next invoices.</p><p><a class="button" href="{{ invoices_url }}">View invoices</a></p>',
    ],
    'ticket_reply' => [
        'name' => 'Support ticket reply',
        'variables' => ['name', 'ticket_subject', 'ticket_url'],
        'subject' => 'New reply to your ticket "{{ ticket_subject }}"',
        'html' => '<p>Hello {{ name }},</p><p>our support team has replied to your ticket <strong>{{ ticket_subject }}</strong>.</p><p><a class="button" href="{{ ticket_url }}">View ticket</a></p>',
    ],
    'ticket_closed' => [
        'name' => 'Support ticket closed (with rating link)',
        'variables' => ['name', 'ticket_subject', 'ticket_url', 'rating_url'],
        'subject' => 'Your ticket "{{ ticket_subject }}" has been closed',
        'html' => '<p>Hello {{ name }},</p><p>we have closed your ticket <strong>{{ ticket_subject }}</strong>. If you still need help, simply reply in the ticket and it will be reopened.</p><p>How satisfied were you with our support? Your rating takes less than a minute:</p><p><a class="button" href="{{ rating_url }}">Rate our support</a></p>',
    ],
    'ticket_assigned' => [
        'name' => 'Support ticket assigned (staff)',
        'variables' => ['name', 'ticket_id', 'ticket_subject', 'priority', 'assigned_by', 'ticket_url'],
        'subject' => 'Ticket #{{ ticket_id }} has been assigned to you',
        'html' => '<p>Hello {{ name }},</p><p>{{ assigned_by }} has assigned ticket <strong>#{{ ticket_id }} – {{ ticket_subject }}</strong> (priority: {{ priority }}) to you.</p><p><a class="button" href="{{ ticket_url }}">Open ticket</a></p>',
    ],
    'status_subscription_confirm' => [
        'name' => 'Status page: confirm subscription',
        'variables' => ['confirm_url', 'expires_hours', 'status_url'],
        'subject' => 'Please confirm your subscription to the {{ company_name }} status page',
        'html' => '<p>Hello,</p><p>you (or someone using your address) asked to receive e-mails about incidents and maintenance on the {{ company_name }} status page. Please confirm within {{ expires_hours }} hours:</p><p><a class="button" href="{{ confirm_url }}">Confirm subscription</a></p><p>If you did not ask for this, simply ignore this e-mail – you will not receive any further messages.</p>',
    ],
    'status_subscriber_update' => [
        'name' => 'Status page: update for subscribers',
        'variables' => ['kind', 'title', 'message', 'window', 'status', 'status_url', 'unsubscribe_url'],
        'subject' => '{{ kind }}: {{ title }}',
        'html' => '<p>Hello,</p><p>there is news on the {{ company_name }} status page:</p><p><strong>{{ title }}</strong><br>{{ window }}<br>Status: {{ status }}</p><p>{{ message }}</p><p><a class="button" href="{{ status_url }}">Open status page</a></p><p>You receive this e-mail because you subscribed to status updates. <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
    ],
    'test' => [
        'name' => 'SMTP test message',
        'variables' => [],
        'subject' => 'Test e-mail from {{ company_name }}',
        'html' => '<p>This is a test message. Your SMTP settings work.</p>',
    ],
    'sepa_prenotification' => [
        'name' => 'SEPA direct debit pre-notification',
        'variables' => ['name', 'invoice_number', 'invoice_total', 'invoice_url', 'debit_date', 'mandate_reference', 'account_last4'],
        'subject' => 'Direct debit announcement for invoice {{ invoice_number }}',
        'html' => '<p>Hello {{ name }},</p><p>we will collect <strong>{{ invoice_total }}</strong> for invoice <strong>{{ invoice_number }}</strong> by SEPA direct debit from your account ending in {{ account_last4 }} on or shortly after <strong>{{ debit_date }}</strong>.</p><p>Mandate reference: {{ mandate_reference }}. The debit is processed by our payment provider Stripe.</p><p>Please make sure your account is sufficiently funded. You can change your payment method in your customer area at any time.</p><p><a class="button" href="{{ invoice_url }}">View invoice</a></p>',
    ],
    'autopay_failed' => [
        'name' => 'Automatic payment failed',
        'variables' => ['name', 'invoice_number', 'invoice_total', 'invoice_url', 'retry_note'],
        'subject' => 'Automatic payment for invoice {{ invoice_number }} failed',
        'html' => '<p>Hello {{ name }},</p><p>we could not collect <strong>{{ invoice_total }}</strong> for invoice <strong>{{ invoice_number }}</strong> with your saved payment method.</p><p>{{ retry_note }}</p><p><a class="button" href="{{ invoice_url }}">Pay invoice</a></p>',
    ],
    'autopay_action_required' => [
        'name' => 'Automatic payment needs confirmation',
        'variables' => ['name', 'invoice_number', 'invoice_total', 'invoice_url', 'retry_note'],
        'subject' => 'Please confirm the payment for invoice {{ invoice_number }}',
        'html' => '<p>Hello {{ name }},</p><p>your bank asks for an additional confirmation of the payment of <strong>{{ invoice_total }}</strong> for invoice <strong>{{ invoice_number }}</strong>, so it could not be collected automatically.</p><p>{{ retry_note }}</p><p><a class="button" href="{{ invoice_url }}">Pay invoice</a></p>',
    ],
    'team_invitation' => [
        'name' => 'Team invitation',
        'variables' => ['owner_name', 'invitation_url', 'expires_days'],
        'subject' => '{{ owner_name }} invites you to their account',
        'html' => '<p>Hello,</p><p><strong>{{ owner_name }}</strong> invites you to access services of their customer account.</p><p>Sign in with this e-mail address (or register with it) and accept the invitation. The link is valid for {{ expires_days }} days.</p><p><a class="button" href="{{ invitation_url }}">Accept invitation</a></p><p>If you do not know the sender, you can ignore this e-mail.</p>',
    ],
    'order_review' => [
        'name' => 'Order under review',
        'variables' => ['name', 'order_number'],
        'subject' => 'Your order {{ order_number }} is being reviewed',
        'html' => '<p>Hello {{ name }},</p><p>thank you for your order <strong>{{ order_number }}</strong>. Before we set it up, our team checks it briefly. This usually takes only a few hours; we will contact you if we need anything.</p>',
    ],
];
