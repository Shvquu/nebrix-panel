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
];
