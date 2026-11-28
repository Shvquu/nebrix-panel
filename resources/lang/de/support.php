<?php

declare(strict_types=1);

/*
 * German texts of the support area: help center, AI assistant, ticket
 * attachments, canned responses, ratings, notifications and status page
 * subscriptions.
 */
return [
    // Help center (public)
    'Help center' => 'Hilfe-Center',
    'Answers to frequently asked questions about our services.' => 'Antworten auf häufige Fragen zu unseren Diensten.',
    'Search the help center' => 'Hilfe-Center durchsuchen',
    'How can we help you?' => 'Wie können wir Ihnen helfen?',
    'There are no help articles yet.' => 'Es gibt noch keine Hilfeartikel.',
    ':n articles' => ':n Artikel',
    'There are no articles in this category yet.' => 'In dieser Kategorie gibt es noch keine Artikel.',
    ':n results for ":query"' => ':n Ergebnisse für „:query“',
    'No articles match your search. Try other words or contact our support.' => 'Keine Artikel passen zu Ihrer Suche. Versuchen Sie andere Begriffe oder wenden Sie sich an unseren Support.',
    'Last updated' => 'Zuletzt aktualisiert',
    'Was this article helpful?' => 'War dieser Artikel hilfreich?',
    'Yes' => 'Ja',
    'No' => 'Nein',
    'Thank you for your feedback.' => 'Vielen Dank für Ihr Feedback.',
    'You have already rated this article.' => 'Sie haben diesen Artikel bereits bewertet.',
    'Sorry that this article did not help. Our support team will answer your question personally.' => 'Schade, dass Ihnen dieser Artikel nicht geholfen hat. Unser Support-Team beantwortet Ihre Frage gern persönlich.',
    'More in this category' => 'Mehr in dieser Kategorie',
    'Still need help?' => 'Noch Fragen?',
    'Our support team is happy to help you personally.' => 'Unser Support-Team hilft Ihnen gern persönlich weiter.',
    'Open a support ticket' => 'Support-Ticket eröffnen',
    'Many questions are already answered in our help center.' => 'Viele Fragen beantwortet bereits unser Hilfe-Center.',
    'Open the help center' => 'Hilfe-Center öffnen',

    // Admin: help center
    'New category' => 'Neue Kategorie',
    'Delete this category?' => 'Diese Kategorie löschen?',
    'Delete category' => 'Kategorie löschen',
    'Only possible while the category has no articles.' => 'Nur möglich, solange die Kategorie keine Artikel enthält.',
    'Address (slug)' => 'Adresse (Slug)',
    'Short description' => 'Kurzbeschreibung',
    'Published' => 'Veröffentlicht',
    'Published (otherwise draft)' => 'Veröffentlicht (sonst Entwurf)',
    'published' => 'veröffentlicht',
    'Lowercase letters, digits and dashes; created from the title when empty.' => 'Kleinbuchstaben, Ziffern und Bindestriche; wird aus dem Titel erzeugt, wenn leer.',
    'Articles answer frequent questions before customers open a ticket. Only published articles in published categories are public.' => 'Artikel beantworten häufige Fragen, bevor Kundinnen und Kunden ein Ticket eröffnen. Öffentlich sind nur veröffentlichte Artikel in veröffentlichten Kategorien.',
    'New article' => 'Neuer Artikel',
    'Articles' => 'Artikel',
    'Categories' => 'Kategorien',
    'No categories yet. Create a category first, then add articles to it.' => 'Noch keine Kategorien. Legen Sie zuerst eine Kategorie an und fügen Sie dann Artikel hinzu.',
    'Search by title …' => 'Nach Titel suchen …',
    'Show all' => 'Alle anzeigen',
    'Helpful' => 'Hilfreich',
    'Not helpful' => 'Nicht hilfreich',
    'No articles.' => 'Keine Artikel.',
    'View public page' => 'Öffentliche Seite ansehen',
    'Delete this article?' => 'Diesen Artikel löschen?',
    'Delete article' => 'Artikel löschen',
    'Text (Markdown)' => 'Text (Markdown)',
    'Supported: # headings, **bold**, *italic*, `code`, lists, > quotes, ``` code blocks and [links](https://…). HTML is shown as text.' => 'Unterstützt: # Überschriften, **fett**, *kursiv*, `Code`, Listen, > Zitate, ``` Codeblöcke und [Links](https://…). HTML wird als Text angezeigt.',
    'Category saved.' => 'Kategorie gespeichert.',
    'Move or delete the articles of this category first.' => 'Verschieben oder löschen Sie zuerst die Artikel dieser Kategorie.',
    'Category deleted.' => 'Kategorie gelöscht.',
    'Article saved.' => 'Artikel gespeichert.',
    'Article deleted.' => 'Artikel gelöscht.',
    'This address is reserved. Please choose another one.' => 'Diese Adresse ist reserviert. Bitte wählen Sie eine andere.',
    'Another category already uses this address.' => 'Eine andere Kategorie verwendet diese Adresse bereits.',
    'Please choose a category.' => 'Bitte wählen Sie eine Kategorie.',
    'Another article in this category already uses this address.' => 'Ein anderer Artikel dieser Kategorie verwendet diese Adresse bereits.',

    // Account: notifications
    'Notifications' => 'Benachrichtigungen',
    'Choose which messages you receive by e-mail.' => 'Legen Sie fest, welche Nachrichten Sie per E-Mail erhalten.',
    'Discord direct message' => 'Discord-Direktnachricht',
    'always sent' => 'immer gesendet',
    'Some e-mails are always sent because they are legally required or protect your account: invoices, payment reminders, security notices (e.g. password changes and sign-in alerts), account e-mails (verification, password reset) and confirmations of contract declarations such as cancellations.' => 'Einige E-Mails werden immer gesendet, weil sie gesetzlich vorgeschrieben sind oder Ihr Konto schützen: Rechnungen, Zahlungserinnerungen, Sicherheitshinweise (z. B. Passwortänderungen und Anmeldewarnungen), Konto-E-Mails (Bestätigung, Passwort zurücksetzen) und Bestätigungen von Vertragserklärungen wie Kündigungen.',
    'Link your Discord account on the dashboard to receive direct messages.' => 'Verknüpfen Sie Ihr Discord-Konto im Dashboard, um Direktnachrichten zu erhalten.',
    'Discord only delivers direct messages while you are on our Discord server and accept direct messages from its members.' => 'Discord stellt Direktnachrichten nur zu, solange Sie auf unserem Discord-Server sind und Direktnachrichten von dessen Mitgliedern erlauben.',
    'Your notification settings have been saved.' => 'Ihre Benachrichtigungseinstellungen wurden gespeichert.',
    'Invoice created' => 'Rechnung erstellt',
    'Invoices are legally required documents and are always sent by e-mail.' => 'Rechnungen sind gesetzlich vorgeschriebene Dokumente und werden immer per E-Mail gesendet.',
    'Payment received' => 'Zahlung eingegangen',
    'Confirmation when a payment has been booked.' => 'Bestätigung, sobald eine Zahlung verbucht wurde.',
    'Service ready' => 'Dienst bereit',
    'When a service you ordered has been set up. The access data stays available in the customer area.' => 'Wenn ein bestellter Dienst eingerichtet wurde. Die Zugangsdaten bleiben im Kundenbereich abrufbar.',
    'Service suspended' => 'Dienst gesperrt',
    'When a service is suspended, e.g. because of an unpaid invoice.' => 'Wenn ein Dienst gesperrt wird, z. B. wegen einer unbezahlten Rechnung.',
    'Ticket replies' => 'Ticket-Antworten',
    'Replies from our support team and the closing of your tickets.' => 'Antworten unseres Support-Teams und das Schließen Ihrer Tickets.',
    'Maintenance and incidents' => 'Wartungsarbeiten und Störungen',
    'Announcements of maintenance and incidents that affect your services.' => 'Ankündigungen von Wartungsarbeiten und Störungen, die Ihre Dienste betreffen.',

    // Discord direct messages and staff channel
    'New invoice :number (:total): :url' => 'Neue Rechnung :number (:total): :url',
    'Payment received for invoice :number.' => 'Zahlung für Rechnung :number eingegangen.',
    'Your service ":service" is ready: :url' => 'Ihr Dienst „:service“ ist bereit: :url',
    'Your service ":service" has been suspended: :url' => 'Ihr Dienst „:service“ wurde gesperrt: :url',
    'New reply to your ticket ":subject": :url' => 'Neue Antwort auf Ihr Ticket „:subject“: :url',
    'Your ticket ":subject" has been closed: :url' => 'Ihr Ticket „:subject“ wurde geschlossen: :url',
    'Maintenance announced: :title – :url' => 'Wartungsarbeiten angekündigt: :title – :url',
    'Incident: :title – :url' => 'Störung: :title – :url',
    'You have a new notification: :url' => 'Sie haben eine neue Benachrichtigung: :url',

    // Settings
    'Support & help center' => 'Support & Hilfe-Center',
    'Enable the public help center (/help)' => 'Öffentliches Hilfe-Center aktivieren (/help)',
    'Suggest help articles while customers write a ticket subject' => 'Beim Schreiben des Ticket-Betreffs passende Hilfeartikel vorschlagen',
    'Offer Discord direct messages to customers with a linked Discord account' => 'Kundinnen und Kunden mit verknüpftem Discord-Konto Direktnachrichten anbieten',
    'Sent by the bot configured under Discord. Customers choose the events themselves; Discord only delivers them while the customer shares the server with the bot and allows direct messages.' => 'Gesendet vom unter Discord eingerichteten Bot. Die Ereignisse wählen Kundinnen und Kunden selbst; Discord stellt sie nur zu, solange sie mit dem Bot auf einem Server sind und Direktnachrichten erlauben.',

    // Permissions and e-mail templates
    'Manage help center categories and articles' => 'Kategorien und Artikel des Hilfe-Centers verwalten',
];
