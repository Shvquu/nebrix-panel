<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Support;

use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Support\ReplyAddress;
use Nebrix\Support\TicketRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Canned responses, assignment notifications, satisfaction ratings and the
 * personal Reply-To address of ticket notifications.
 */
final class TicketWorkflowTest extends FeatureTestCase
{
    private function ticket(User $customer, string $subject = 'Mail server refuses login'): int
    {
        return $this->app->container()->get(TicketRepository::class)->create($customer->id, $subject, 'I cannot log in to my mailbox since this morning.', null, 'normal');
    }

    public function testCannedResponsesWithPlaceholders(): void
    {
        $this->actingAs($this->createUser(Role::FINANCE));
        self::assertSame(403, $this->get('/admin/canned-responses')->status());
        $this->post('/admin/canned-responses', ['title' => 'Nope', 'body' => 'x']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_canned_responses`'));

        $agent = $this->createUser(Role::SUPPORT, ['first_name' => 'Sam', 'last_name' => 'Agent']);
        $this->actingAs($agent);
        $this->post('/admin/canned-responses', ['title' => 'Greeting', 'body' => "Hello {customer_name},\nthanks for ticket #{ticket_id} (\"{ticket_subject}\").\n<b>{staff_name}</b>", 'sort_order' => '1']);
        $cannedId = (int) $this->db->fetchValue('SELECT `id` FROM `nx_canned_responses`');
        self::assertGreaterThan(0, $cannedId);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'canned_response.created'"));

        $customer = $this->createUser(Role::CUSTOMER, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $ticketId = $this->ticket($customer);
        $page = $this->get('/admin/tickets/' . $ticketId)->body();
        // Filled in on the server and escaped in the data attribute.
        self::assertStringContainsString('data-canned-body="Hello Ada Lovelace,&#10;thanks for ticket #' . $ticketId . ' (&quot;Mail server refuses login&quot;).&#10;&lt;b&gt;Sam Agent&lt;/b&gt;"', str_replace("\n", '&#10;', $page));
        self::assertStringContainsString('data-canned-select', $page);

        // Placeholders typed into a reply are replaced as well.
        $this->post('/admin/tickets/' . $ticketId . '/reply', ['message' => 'Dear {customer_name}, regards {staff_name}']);
        self::assertSame('Dear Ada Lovelace, regards Sam Agent', $this->db->fetchValue('SELECT `message` FROM `nx_ticket_messages` WHERE `ticket_id` = ? AND `is_staff` = 1', [$ticketId]));

        $this->post('/admin/canned-responses/' . $cannedId, ['title' => 'Greeting 2', 'body' => 'Hi']);
        self::assertSame('Greeting 2', $this->db->fetchValue('SELECT `title` FROM `nx_canned_responses` WHERE `id` = ?', [$cannedId]));
        $this->post('/admin/canned-responses/' . $cannedId . '/delete');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_canned_responses`'));
    }

    public function testAssignmentNotifiesTheAssigneeAndMyTicketsFilter(): void
    {
        $customer = $this->createUser();
        $ticketId = $this->ticket($customer, 'Domain transfer stuck');
        $lead = $this->createUser(Role::ADMIN, ['first_name' => 'Lea', 'last_name' => 'Lead']);
        $agent = $this->createUser(Role::SUPPORT, ['first_name' => 'Sam', 'last_name' => 'Agent']);

        $this->actingAs($lead);
        $this->post('/admin/tickets/' . $ticketId, ['status' => 'open', 'priority' => 'high', 'assigned_to' => (string) $agent->id]);
        $mail = $this->lastMail('ticket_assigned');
        self::assertNotNull($mail);
        self::assertSame($agent->email, $mail['to']);
        self::assertStringContainsString('#' . $ticketId, $mail['subject']);
        self::assertStringContainsString('Lea Lead', $mail['text']);
        self::assertStringContainsString('/admin/tickets/' . $ticketId, $mail['text']);

        // Saving again without a change, or assigning oneself, sends nothing.
        $this->post('/admin/tickets/' . $ticketId, ['status' => 'open', 'priority' => 'high', 'assigned_to' => (string) $agent->id]);
        $this->actingAs($agent);
        $this->post('/admin/tickets/' . $ticketId, ['status' => 'open', 'priority' => 'high', 'assigned_to' => (string) $agent->id]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_jobs` WHERE `type` = 'mail.send' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.template')) = 'ticket_assigned'"));

        self::assertStringContainsString('Domain transfer stuck', $this->get('/admin/tickets', ['mine' => '1', 'status' => ''])->body());
        $this->actingAs($lead);
        self::assertStringNotContainsString('Domain transfer stuck', $this->get('/admin/tickets', ['mine' => '1', 'status' => ''])->body());

        // Customers cannot be assigned.
        self::assertSame(400, $this->post('/admin/tickets/' . $ticketId, ['status' => 'open', 'priority' => 'high', 'assigned_to' => (string) $customer->id])->status());
    }

    public function testClosedTicketsAreRatedOnceByTheirOwner(): void
    {
        $owner = $this->createUser(Role::CUSTOMER, ['first_name' => 'Ada']);
        $ticketId = $this->ticket($owner);

        // Open tickets cannot be rated.
        $this->actingAs($owner);
        self::assertSame(404, $this->post('/tickets/' . $ticketId . '/rating', ['rating' => '5'])->status());

        // Closing by staff sends the closing e-mail with the rating link.
        $this->actingAs($this->createUser(Role::SUPPORT));
        $this->post('/admin/tickets/' . $ticketId, ['status' => 'closed', 'priority' => 'normal', 'assigned_to' => '']);
        $mail = $this->lastMail('ticket_closed');
        self::assertNotNull($mail);
        self::assertSame($owner->email, $mail['to']);
        self::assertStringContainsString('http://localhost/tickets/' . $ticketId . '#rating', $mail['html']);

        // Somebody else cannot rate the ticket (IDOR).
        $other = $this->createUser();
        $this->actingAs($other);
        self::assertSame(404, $this->post('/tickets/' . $ticketId . '/rating', ['rating' => '1'])->status());
        self::assertNull($this->db->fetchValue('SELECT `rating` FROM `nx_ticket_ratings` WHERE `ticket_id` = ?', [$ticketId]));

        $this->actingAs($owner);
        self::assertStringContainsString('How satisfied were you with our support?', $this->get('/tickets/' . $ticketId)->body());
        $this->post('/tickets/' . $ticketId . '/rating', ['rating' => '6']);
        self::assertNull($this->db->fetchValue('SELECT `rating` FROM `nx_ticket_ratings` WHERE `ticket_id` = ?', [$ticketId]), 'Only 1–5.');
        $this->post('/tickets/' . $ticketId . '/rating', ['rating' => '4', 'comment' => 'Quick <b>help</b>, thanks!']);
        $this->post('/tickets/' . $ticketId . '/rating', ['rating' => '1', 'comment' => 'changed my mind']);
        $rating = (array) $this->db->fetchOne('SELECT * FROM `nx_ticket_ratings` WHERE `ticket_id` = ?', [$ticketId]);
        self::assertSame([4, 'Quick <b>help</b>, thanks!', $owner->id], [(int) $rating['rating'], $rating['comment'], (int) $rating['user_id']], 'Rated once.');
        self::assertStringContainsString('You have already rated this ticket.', $this->get('/tickets/' . $ticketId)->body());

        // Staff see the rating (escaped) and the average in the overview.
        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertStringContainsString('Quick &lt;b&gt;help&lt;/b&gt;, thanks!', $this->get('/admin/tickets/' . $ticketId)->body());
        $second = $this->ticket($other, 'Second');
        $this->post('/admin/tickets/' . $second, ['status' => 'closed', 'priority' => 'normal', 'assigned_to' => '']);
        $this->actingAs($other);
        $this->post('/tickets/' . $second . '/rating', ['rating' => '5']);
        $this->actingAs($this->createUser(Role::SUPPORT));
        $overview = $this->get('/admin/tickets', ['status' => 'closed'])->body();
        self::assertStringContainsString('<strong>4.5 / 5</strong> (2 ratings)', $overview);

        // Ratings can be switched off.
        $this->app->container()->get(SettingsRepository::class)->set('support.ratings_enabled', false);
        $third = $this->ticket($owner, 'Third');
        $this->post('/admin/tickets/' . $third, ['status' => 'closed', 'priority' => 'normal', 'assigned_to' => '']);
        $this->actingAs($owner);
        self::assertSame(404, $this->post('/tickets/' . $third . '/rating', ['rating' => '5'])->status());
        self::assertStringNotContainsString('How satisfied were you with our support?', $this->get('/tickets/' . $third)->body());
    }

    public function testNotificationsCarryThePersonalReplyAddress(): void
    {
        $customer = $this->createUser();
        $ticketId = $this->ticket($customer);
        $this->actingAs($this->createUser(Role::SUPPORT));

        // Off by default: the normal support address applies.
        $this->post('/admin/tickets/' . $ticketId . '/reply', ['message' => 'We are looking into it.']);
        self::assertSame([], $this->lastMail('ticket_reply')['headers'] ?? []);

        $settings = $this->app->container()->get(SettingsRepository::class);
        $settings->set('support.email_replies_enabled', true);
        $settings->set('support.reply_domain', 'support.example.test');
        $settings->set('support.inbound_secret', str_repeat('s3cr3t-', 6));
        $this->post('/admin/tickets/' . $ticketId . '/reply', ['message' => 'Please try again now.']);
        $replyTo = (string) ($this->lastMail('ticket_reply')['headers']['reply_to'] ?? '');
        self::assertMatchesRegularExpression('/^support\+' . $ticketId . '\.[a-f0-9]{24}@support\.example\.test$/', $replyTo);
        $addresses = $this->app->container()->get(ReplyAddress::class);
        self::assertSame($ticketId, $addresses->parse($replyTo));
        self::assertSame($ticketId, $addresses->parse(strtoupper($replyTo)), 'Case-insensitive.');
        self::assertNull($addresses->parse(str_replace('+' . $ticketId . '.', '+' . ($ticketId + 1) . '.', $replyTo)), 'The signature is bound to the ticket.');
        self::assertNull($addresses->parse(str_replace('@support.example.test', '@evil.test', $replyTo)));
    }
}
