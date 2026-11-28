<?php

declare(strict_types=1);

/*
 * Finance area settings (Admin → Settings → Finance): accounting export
 * (DATEV), lexoffice, bank statement import, gift cards, partner program and
 * reseller tiers. Same format as config/settings.php.
 *
 * Account numbers left empty use the default of the chosen chart of
 * accounts (see Nebrix\Finance\Accounting\ChartOfAccounts) – have them
 * checked by your tax advisor.
 */

// A closure keeps the helper variables out of the including file's scope.
return (static function (): array {
    $account = 'nullable|regex:/^(\d{1,9})?$/';
    $uuid = 'nullable|regex:/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})?$/';
    $amount = 'required|regex:/^\\d{1,6}([.,]\\d{1,2})?$/';
    $months = ['1' => 'January', '2' => 'February', '3' => 'March', '4' => 'April', '5' => 'May', '6' => 'June', '7' => 'July', '8' => 'August', '9' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'];

    return [
        // ------------------------------------------------------- accounting export
        'accounting.consultant_number' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'DATEV consultant number (Beraternummer)', 'rules' => 'nullable|regex:/^(\d{4,7})?$/', 'help' => 'From your tax advisor; 1001–9999999.'],
        'accounting.client_number' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'DATEV client number (Mandantennummer)', 'rules' => 'nullable|regex:/^(\d{1,5})?$/'],
        'accounting.chart' => ['group' => 'accounting', 'type' => 'select', 'default' => 'skr03', 'label' => 'Chart of accounts', 'options' => ['skr03' => 'SKR 03', 'skr04' => 'SKR 04']],
        'accounting.account_length' => ['group' => 'accounting', 'type' => 'int', 'default' => 4, 'label' => 'Length of general ledger accounts (Sachkontenlänge)', 'rules' => 'required|int|min:4|max:8', 'help' => 'Customer (debtor) accounts have one digit more.'],
        'accounting.fiscal_year_start' => ['group' => 'accounting', 'type' => 'select', 'default' => '1', 'label' => 'First month of the fiscal year', 'options' => $months],
        'accounting.lock_bookings' => ['group' => 'accounting', 'type' => 'bool', 'default' => false, 'label' => 'Mark exported bookings as final (Festschreibung)', 'help' => 'Usually left off so that your tax advisor can check the bookings before they are locked.'],
        'accounting.debtor_mode' => ['group' => 'accounting', 'type' => 'select', 'default' => 'per_customer', 'label' => 'Customer accounts', 'options' => ['per_customer' => 'One debtor account per customer (base number + customer number)', 'collective' => 'One collective debtor account for all customers']],
        'accounting.debtor_account' => ['group' => 'accounting', 'type' => 'string', 'default' => '10000', 'label' => 'Debtor account (collective account or base number)', 'rules' => 'required|regex:/^\d{5,9}$/', 'help' => 'Per customer: base + customer number, e.g. 10000 + 42 = 10042.'],
        'accounting.account_revenue_standard' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'Revenue account: domestic, standard rate', 'rules' => $account, 'help' => 'Empty = SKR 03: 8400, SKR 04: 4400 (automatic VAT accounts).'],
        'accounting.account_revenue_reduced' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'Revenue account: domestic, reduced rate', 'rules' => $account, 'help' => 'Empty = SKR 03: 8300, SKR 04: 4300.'],
        'accounting.account_revenue_reverse_charge' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'Revenue account: EU reverse charge', 'rules' => $account, 'help' => 'Empty = SKR 03: 8336, SKR 04: 4336. The customer\'s VAT ID is exported for the EC Sales List.'],
        'accounting.account_revenue_oss' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'Revenue account: EU consumers (OSS)', 'rules' => $account, 'help' => 'Empty = SKR 03: 8320, SKR 04: 4320. Country and rate are exported in the fields for the country of destination.'],
        'accounting.account_revenue_third_country' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'Revenue account: customers outside the EU (not taxable in Germany)', 'rules' => $account, 'help' => 'Empty = SKR 03: 8338, SKR 04: 4338.'],
        'accounting.account_revenue_small_business' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'Revenue account: small business (§ 19 UStG)', 'rules' => $account, 'help' => 'Empty = SKR 03: 8195, SKR 04: 4185.'],
        'accounting.account_customer_credit' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'Account for prepaid customer credit (top-ups, gift cards)', 'rules' => $account, 'help' => 'Empty = SKR 03: 1710, SKR 04: 3250 (received prepayments). Top-ups and gift cards are not revenue; VAT is due on the invoices paid with the credit.'],
        'accounting.bu_keys' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'BU keys per tax case (only for accounts without automatic VAT)', 'rules' => 'nullable|string|max:200|regex:/^((standard|reduced|reverse_charge|oss|third_country|small_business)=\d{1,4}[\s,;]*)*$/', 'help' => 'e.g. "reverse_charge=94, oss=240" – empty for the default automatic accounts.'],
        'accounting.export_payments' => ['group' => 'accounting', 'type' => 'bool', 'default' => false, 'label' => 'Also export payment bookings (bank, PayPal, Stripe, credit)', 'help' => 'Payments and refunds are booked against the clearing accounts below.'],
        'accounting.account_bank' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'Bank account (bank transfers, manual payments)', 'rules' => $account, 'help' => 'Empty = SKR 03: 1200, SKR 04: 1800.'],
        'accounting.account_paypal' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'Clearing account PayPal', 'rules' => $account, 'help' => 'Empty = SKR 03: 1360, SKR 04: 1460 (money in transit).'],
        'accounting.account_stripe' => ['group' => 'accounting', 'type' => 'string', 'default' => '', 'label' => 'Clearing account Stripe', 'rules' => $account, 'help' => 'Empty = SKR 03: 1360, SKR 04: 1460 (money in transit).'],

        // --------------------------------------------------------------- lexoffice
        'lexoffice.enabled' => ['group' => 'lexoffice', 'type' => 'bool', 'default' => false, 'label' => 'Transfer invoices to lexoffice', 'help' => 'Creates a voucher with the invoice PDF for every issued invoice and correction document (tested against a mock only).'],
        'lexoffice.api_key' => ['group' => 'lexoffice', 'type' => 'string', 'default' => null, 'label' => 'lexoffice API key', 'secret' => true, 'rules' => 'nullable|string|max:200|regex:/^[A-Za-z0-9._-]+$/'],
        'lexoffice.auto_sync' => ['group' => 'lexoffice', 'type' => 'bool', 'default' => false, 'label' => 'Transfer automatically (every hour)', 'help' => 'Off: only with "Sync now" on the accounting page.'],
        'lexoffice.sync_from' => ['group' => 'lexoffice', 'type' => 'string', 'default' => '', 'label' => 'Only documents issued on or after (YYYY-MM-DD)', 'rules' => 'nullable|regex:/^(\d{4}-\d{2}-\d{2})?$/', 'help' => 'Avoids transferring documents your tax advisor already booked.'],
        'lexoffice.category_standard' => ['group' => 'lexoffice', 'type' => 'string', 'default' => '8f8664a8-fd86-11e1-a21f-0800200c9a66', 'label' => 'Posting category: domestic revenue', 'rules' => $uuid, 'help' => 'Posting category ID from lexoffice (GET /v1/posting-categories). The default is "Einnahmen".'],
        'lexoffice.category_oss' => ['group' => 'lexoffice', 'type' => 'string', 'default' => '', 'label' => 'Posting category: EU consumers (OSS)', 'rules' => $uuid, 'help' => 'Required before OSS invoices can be transferred.'],
        'lexoffice.category_reverse_charge' => ['group' => 'lexoffice', 'type' => 'string', 'default' => '', 'label' => 'Posting category: EU reverse charge', 'rules' => $uuid, 'help' => 'Required before invoices with reverse charge can be transferred.'],
        'lexoffice.category_third_country' => ['group' => 'lexoffice', 'type' => 'string', 'default' => '', 'label' => 'Posting category: customers outside the EU', 'rules' => $uuid],
        'lexoffice.category_small_business' => ['group' => 'lexoffice', 'type' => 'string', 'default' => '', 'label' => 'Posting category: small business', 'rules' => $uuid],

        // -------------------------------------------------------------- bank import
        'bank.auto_book' => ['group' => 'bank', 'type' => 'bool', 'default' => true, 'label' => 'Book clear matches automatically', 'help' => 'An incoming transfer with exactly one open invoice number in the reference and exactly the open amount is booked as payment. Everything else goes to the review list.'],
        'bank.max_upload_kb' => ['group' => 'bank', 'type' => 'int', 'default' => 5120, 'label' => 'Maximum statement file size (KB)', 'rules' => 'required|int|min:64|max:20480'],

        // ------------------------------------------------------------- gift cards
        'giftcard.enabled' => ['group' => 'giftcard', 'type' => 'bool', 'default' => false, 'label' => 'Customers can redeem gift cards', 'help' => 'The value is booked as account credit.'],
        'giftcard.sale_enabled' => ['group' => 'giftcard', 'type' => 'bool', 'default' => false, 'label' => 'Customers can buy gift cards', 'help' => 'Sold as multi-purpose vouchers (§ 3 Abs. 15 UStG) without VAT; the code is e-mailed after the payment is confirmed.'],
        'giftcard.sale_min' => ['group' => 'giftcard', 'type' => 'string', 'default' => '10.00', 'label' => 'Minimum value of a bought gift card', 'rules' => $amount],
        'giftcard.sale_max' => ['group' => 'giftcard', 'type' => 'string', 'default' => '500.00', 'label' => 'Maximum value of a bought gift card', 'rules' => $amount],
        'giftcard.validity_months' => ['group' => 'giftcard', 'type' => 'int', 'default' => 36, 'label' => 'Validity of bought gift cards (months, 0 = unlimited)', 'rules' => 'required|int|min:0|max:120', 'help' => 'In Germany vouchers should be valid for at least the regular limitation period (three years to the end of the year).'],
        'giftcard.tax_note' => ['group' => 'giftcard', 'type' => 'string', 'default' => 'Mehrzweck-Gutschein (§ 3 Abs. 15 UStG): Der Verkauf ist nicht umsatzsteuerbar; die Umsatzsteuer entsteht bei der Einlösung.', 'label' => 'Note on gift card invoices', 'rules' => 'required|string|max:255'],

        // --------------------------------------------------------- partner program
        'partner.enabled' => ['group' => 'partner', 'type' => 'bool', 'default' => false, 'label' => 'Enable the partner program (cash commissions)', 'help' => 'Customers can opt in. Requires "Refer a friend"; referrals of partners earn a commission on paid invoices instead of the credit reward.'],
        'partner.commission_percent' => ['group' => 'partner', 'type' => 'string', 'default' => '10', 'label' => 'Commission (percent of the paid net amount)', 'rules' => 'required|regex:/^\d{1,2}([.,]\d{1,2})?$/'],
        'partner.commission_months' => ['group' => 'partner', 'type' => 'int', 'default' => 12, 'label' => 'Commission for invoices paid within … months after the sign-up', 'rules' => 'required|int|min:1|max:120'],
        'partner.holding_days' => ['group' => 'partner', 'type' => 'int', 'default' => 30, 'label' => 'Holding period before a commission can be paid out (days)', 'rules' => 'required|int|min:0|max:365', 'help' => 'Covers the withdrawal and chargeback period. Refunded, cancelled or charged-back invoices reverse their commission.'],
        'partner.min_payout' => ['group' => 'partner', 'type' => 'string', 'default' => '50.00', 'label' => 'Minimum payout amount', 'rules' => $amount],
        'partner.terms_url' => ['group' => 'partner', 'type' => 'url', 'default' => '', 'label' => 'Partner terms URL', 'help' => 'Partners must accept these terms when joining.'],
        'partner.terms_version' => ['group' => 'partner', 'type' => 'string', 'default' => '1', 'label' => 'Version of the partner terms', 'rules' => 'required|string|max:50', 'help' => 'Stored with each consent.'],
        'partner.replace_referral_reward' => ['group' => 'partner', 'type' => 'bool', 'default' => true, 'label' => 'Partners receive no credit reward for their referrals', 'help' => 'The welcome credit for the referred customer is still granted.'],

        // ----------------------------------------------------------- reseller tiers
        'tiers.enabled' => ['group' => 'tiers', 'type' => 'bool', 'default' => false, 'label' => 'Assign customer groups automatically by tier rules', 'help' => 'Evaluated daily. Only customers without a group or in a group used by a tier rule are changed.'],
        'tiers.downgrade_period_days' => ['group' => 'tiers', 'type' => 'int', 'default' => 90, 'label' => 'At most one downgrade per … days', 'rules' => 'required|int|min:1|max:730'],
    ];
})();
