<?php

declare(strict_types=1);

/*
 * German texts of the billing core: saved payment methods, automatic payments.
 */

return [
    'Add card' => 'Karte hinzufügen',
    'Add card or SEPA direct debit' => 'Karte oder SEPA-Lastschrift hinzufügen',
    'Allow customers to save a payment method for automatic renewal payments' => 'Kunden dürfen eine Zahlungsart für automatische Verlängerungszahlungen speichern',
    'Automatic payment' => 'Automatische Zahlung',
    'Automatic payment failed' => 'Automatische Zahlung fehlgeschlagen',
    'Automatic payment has been switched off.' => 'Die automatische Zahlung wurde ausgeschaltet.',
    'Automatic payment needs confirmation' => 'Automatische Zahlung muss bestätigt werden',
    'Charged at the earliest one day before the due date. Account credit is used first when it covers the invoice. You can switch this off at any time.' => 'Die Abbuchung erfolgt frühestens einen Tag vor Fälligkeit. Deckt Ihr Guthaben die Rechnung, wird zuerst das Guthaben verwendet. Sie können dies jederzeit ausschalten.',
    'Customers save a card (or a SEPA mandate) in their account and can have renewal invoices charged automatically. Add the webhook events payment_intent.succeeded, payment_intent.payment_failed, payment_method.detached and mandate.updated in Stripe.' => 'Kunden speichern eine Karte (oder ein SEPA-Mandat) im Kundenkonto und lassen Verlängerungsrechnungen automatisch abbuchen. Ergänzen Sie in Stripe die Webhook-Ereignisse payment_intent.succeeded, payment_intent.payment_failed, payment_method.detached und mandate.updated.',
    'Days between the SEPA pre-notification and the debit' => 'Tage zwischen SEPA-Vorabankündigung und Einzug',
    'Default' => 'Standard',
    'For SEPA direct debits you receive a pre-notification with amount and date before each debit.' => 'Bei SEPA-Lastschriften erhalten Sie vor jedem Einzug eine Vorabankündigung mit Betrag und Datum.',
    'Make default' => 'Als Standard festlegen',
    'Mandate reference' => 'Mandatsreferenz',
    'Must not be shorter than the period agreed in the mandate text.' => 'Darf nicht kürzer sein als die im Mandatstext vereinbarte Frist.',
    'Offer SEPA direct debit as a saved payment method' => 'SEPA-Lastschrift als gespeicherte Zahlungsart anbieten',
    'Only for invoices in EUR. SEPA Direct Debit must be activated in your Stripe account.' => 'Nur für Rechnungen in EUR. SEPA-Lastschrift muss in Ihrem Stripe-Konto aktiviert sein.',
    'Pay renewal invoices automatically with my default payment method' => 'Verlängerungsrechnungen automatisch mit meiner Standard-Zahlungsart bezahlen',
    'Payment method' => 'Zahlungsart',
    'Payment methods' => 'Zahlungsarten',
    'Please pay the invoice manually.' => 'Bitte bezahlen Sie die Rechnung manuell.',
    'Please save a payment method first.' => 'Bitte speichern Sie zuerst eine Zahlungsart.',
    'Recent automatic payments' => 'Letzte automatische Zahlungen',
    'Remove this payment method?' => 'Diese Zahlungsart entfernen?',
    'Renewal invoices are now paid automatically.' => 'Verlängerungsrechnungen werden jetzt automatisch bezahlt.',
    'SEPA direct debit' => 'SEPA-Lastschrift',
    'SEPA direct debit pre-notification' => 'Vorabankündigung SEPA-Lastschrift',
    'Save a payment method to have renewal invoices paid automatically. Card details are stored by our payment provider Stripe, not by us.' => 'Speichern Sie eine Zahlungsart, damit Verlängerungsrechnungen automatisch bezahlt werden. Die Kartendaten speichert unser Zahlungsdienstleister Stripe, nicht wir.',
    'Saved payment methods' => 'Gespeicherte Zahlungsarten',
    'Saving the payment method was cancelled.' => 'Das Speichern der Zahlungsart wurde abgebrochen.',
    'The default payment method has been changed.' => 'Die Standard-Zahlungsart wurde geändert.',
    'The payment method could not be confirmed. If you completed the form at Stripe, it will appear here shortly.' => 'Die Zahlungsart konnte nicht bestätigt werden. Wenn Sie das Formular bei Stripe abgeschlossen haben, erscheint sie in Kürze hier.',
    'The payment method could not be removed at the payment provider. Please try again later.' => 'Die Zahlungsart konnte beim Zahlungsdienstleister nicht entfernt werden. Bitte versuchen Sie es später erneut.',
    'The payment method has been removed.' => 'Die Zahlungsart wurde entfernt.',
    'The payment provider is currently not available. Please try again later.' => 'Der Zahlungsdienstleister ist derzeit nicht erreichbar. Bitte versuchen Sie es später erneut.',
    'We will try again in a few days.' => 'Wir versuchen die Abbuchung in einigen Tagen erneut.',
    'You can save up to five payment methods. Please remove one first.' => 'Sie können bis zu fünf Zahlungsarten speichern. Bitte entfernen Sie zuerst eine.',
    'You have not saved a payment method yet.' => 'Sie haben noch keine Zahlungsart gespeichert.',
    'Your payment method has been saved.' => 'Ihre Zahlungsart wurde gespeichert.',
    'confirmation required' => 'Bestätigung erforderlich',
    'in progress' => 'in Bearbeitung',
];
