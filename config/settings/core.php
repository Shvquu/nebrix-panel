<?php

declare(strict_types=1);

/*
 * Settings of the billing core: saved payment methods and automatic payments.
 * Same format as config/settings.php.
 */

return [
    'payments.stripe.saved_methods' => ['group' => 'payments_stripe', 'type' => 'bool', 'default' => false, 'label' => 'Allow customers to save a payment method for automatic renewal payments', 'help' => 'Customers save a card (or a SEPA mandate) in their account and can have renewal invoices charged automatically. Add the webhook events payment_intent.succeeded, payment_intent.payment_failed, payment_method.detached and mandate.updated in Stripe.'],
    'payments.stripe.sepa_debit' => ['group' => 'payments_stripe', 'type' => 'bool', 'default' => false, 'label' => 'Offer SEPA direct debit as a saved payment method', 'help' => 'Only for invoices in EUR. SEPA Direct Debit must be activated in your Stripe account.'],
    'payments.stripe.sepa_prenotice_days' => ['group' => 'payments_stripe', 'type' => 'int', 'default' => 2, 'label' => 'Days between the SEPA pre-notification and the debit', 'help' => 'Must not be shorter than the period agreed in the mandate text.', 'rules' => 'required|int|min:1|max:14'],
    'fraud.enabled' => ['group' => 'fraud', 'type' => 'bool', 'default' => false, 'label' => 'Check paid orders for fraud before they are set up', 'help' => 'Orders reaching the review score wait in Orders → Order review until a staff member approves them.'],
    'fraud.review_score' => ['group' => 'fraud', 'type' => 'int', 'default' => 50, 'label' => 'Review score', 'help' => 'Points per rule: throw-away e-mail 40, payment country differs from billing country 25, several accounts from the same IP 25, IP of accounts with chargebacks 30, new account with a high order value 20, more than three orders in 24 hours 15, incomplete billing details 10, earlier chargeback 60, earlier rejected order 60.', 'rules' => 'required|int|min:1|max:500'],
    'fraud.high_value_amount' => ['group' => 'fraud', 'type' => 'int', 'default' => 100, 'label' => 'High order value for new accounts (whole currency units)', 'rules' => 'required|int|min:0|max:1000000'],
    'fraud.blocked_email_domains' => ['group' => 'fraud', 'type' => 'text', 'default' => '', 'label' => 'Additional throw-away e-mail domains', 'help' => 'One domain per line, in addition to the built-in list. Subdomains match as well.', 'rules' => 'nullable|string|max:10000'],
];
