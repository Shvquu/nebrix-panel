<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Support;

use Nebrix\Auth\Role;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Ticket attachments: content-based type check, limits, storage outside
 * the document root with random names and downloads only for the ticket
 * owner and staff with the ticket permission (IDOR).
 */
final class TicketAttachmentTest extends FeatureTestCase
{
    use SupportHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryAttachments();
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryFiles();
        parent::tearDown();
    }

    private function openTicket(array $files): \Nebrix\Http\Response
    {
        return $this->postWithFiles('/tickets', ['subject' => 'Server does not boot', 'message' => 'It hangs at the boot loader since today.', 'priority' => 'normal'], $files);
    }

    public function testCustomerAndStaffShareAttachmentsOnlyWithAuthorizedUsers(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);
        $response = $this->openTicket([
            $this->uploadedFile('../../screenshot.png', self::png(), 'image/png'),
            $this->uploadedFile('boot.log', "[ 0.000] Booting\n[ 1.204] kernel panic\n", 'text/plain'),
        ]);
        $ticketId = (int) $this->db->fetchValue('SELECT `id` FROM `nx_tickets` WHERE `user_id` = ?', [$owner->id]);
        $this->assertRedirect($response, '/tickets/' . $ticketId);

        $rows = $this->db->fetchAll('SELECT * FROM `nx_ticket_attachments` WHERE `ticket_id` = ? ORDER BY `id`', [$ticketId]);
        self::assertCount(2, $rows);
        self::assertSame(['screenshot.png', 'image/png', 0], [$rows[0]['original_name'], $rows[0]['mime'], (int) $rows[0]['is_staff']], 'Path parts are removed from names.');
        self::assertSame('text/plain', $rows[1]['mime']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', (string) $rows[0]['stored_name']);
        self::assertFileExists($this->attachmentDir . '/' . $rows[0]['stored_name']);
        self::assertSame((int) $this->db->fetchValue('SELECT MIN(`id`) FROM `nx_ticket_messages` WHERE `ticket_id` = ?', [$ticketId]), (int) $rows[0]['message_id']);
        self::assertStringContainsString('/tickets/' . $ticketId . '/attachments/' . $rows[0]['id'], $this->get('/tickets/' . $ticketId)->body());

        // The owner downloads it as attachment, never rendered inline.
        $download = $this->get('/tickets/' . $ticketId . '/attachments/' . $rows[1]['id']);
        self::assertSame(200, $download->status());
        self::assertStringContainsString('kernel panic', $download->body());
        self::assertStringStartsWith('attachment; filename="boot.log"', (string) $download->header('Content-Disposition'));
        self::assertSame('nosniff', $download->header('X-Content-Type-Options'));
        self::assertSame('text/plain; charset=UTF-8', $download->header('Content-Type'));
        self::assertStringContainsString('sandbox', (string) $download->header('Content-Security-Policy'));

        // Another customer gets 404 – for the ticket and for an attachment ID of it under their own ticket.
        $other = $this->createUser();
        $this->actingAs($other);
        self::assertSame(404, $this->get('/tickets/' . $ticketId . '/attachments/' . $rows[0]['id'])->status());
        $this->post('/tickets', ['subject' => 'My own ticket', 'message' => 'A question about my invoice.', 'priority' => 'low']);
        $ownTicket = (int) $this->db->fetchValue('SELECT `id` FROM `nx_tickets` WHERE `user_id` = ?', [$other->id]);
        self::assertSame(404, $this->get('/tickets/' . $ownTicket . '/attachments/' . $rows[0]['id'])->status());
        self::assertNotSame(200, $this->get('/admin/tickets/' . $ticketId . '/attachments/' . $rows[0]['id'])->status(), 'Customers have no staff access.');

        // Guests are sent to the sign-in.
        $this->cookies = [];
        self::assertTrue($this->get('/tickets/' . $ticketId . '/attachments/' . $rows[0]['id'])->isRedirect());

        // Staff with tickets.view download it; staff without it do not.
        $this->actingAs($this->createUser(Role::SUPPORT));
        $staffDownload = $this->get('/admin/tickets/' . $ticketId . '/attachments/' . $rows[0]['id']);
        self::assertSame(200, $staffDownload->status());
        self::assertSame(self::png(), $staffDownload->body());
        self::assertSame('image/png', $staffDownload->header('Content-Type'));
        self::assertSame(404, $this->get('/admin/tickets/' . $ownTicket . '/attachments/' . $rows[0]['id'])->status(), 'Attachment and ticket must match.');
        $this->actingAs($this->createUser(Role::FINANCE));
        self::assertSame(403, $this->get('/admin/tickets/' . $ticketId . '/attachments/' . $rows[0]['id'])->status());

        // Staff reply with a PDF; the customer can download it.
        $this->actingAs($this->createUser(Role::SUPPORT));
        $this->postWithFiles('/admin/tickets/' . $ticketId . '/reply', ['message' => 'Please check the attached guide.'], [$this->uploadedFile('guide.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n", 'application/pdf')]);
        $pdf = (array) $this->db->fetchOne('SELECT * FROM `nx_ticket_attachments` WHERE `ticket_id` = ? AND `is_staff` = 1', [$ticketId]);
        self::assertSame('application/pdf', $pdf['mime']);
        $this->actingAs($owner);
        self::assertSame(200, $this->get('/tickets/' . $ticketId . '/attachments/' . $pdf['id'])->status());

        // A file changed on disk is not served.
        file_put_contents($this->attachmentDir . '/' . $rows[1]['stored_name'], 'tampered');
        self::assertSame(404, $this->get('/tickets/' . $ticketId . '/attachments/' . $rows[1]['id'])->status());
    }

    public function testUploadsAreCheckedByContentAndLimited(): void
    {
        $customer = $this->createUser();
        $this->actingAs($customer);
        $settings = $this->app->container()->get(SettingsRepository::class);
        $count = fn (): int => (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_tickets` WHERE `user_id` = ?', [$customer->id]);

        // Scripts, HTML and SVG are refused, whatever the name or claimed type says.
        foreach ([
            ['fake.pdf', 'not a pdf at all, just text with %PDF- later', 'application/pdf'],
            ['image.png', "<?php system(\$_GET['c']); ?>", 'image/png'],
            ['page.html', '<html><body><script>alert(1)</script></body></html>', 'text/html'],
            ['logo.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
            ['archive.zip', "PK\x03\x04" . str_repeat("\x00", 40), 'application/zip'],
        ] as [$name, $content, $type]) {
            $response = $this->openTicket([$this->uploadedFile($name, $content, $type)]);
            self::assertTrue($response->isRedirect(), $name);
            self::assertSame(0, $count(), $name . ' was accepted.');
        }
        self::assertStringContainsString('Only images (PNG, JPEG, GIF, WebP), PDF documents and plain text files are allowed.', $this->get('/tickets/create')->body());

        // A text file named .pdf is stored as what it is.
        $this->openTicket([$this->uploadedFile('notes.pdf', "just some notes\n", 'application/pdf')]);
        self::assertSame(1, $count());
        self::assertSame('text/plain', $this->db->fetchValue('SELECT `mime` FROM `nx_ticket_attachments` ORDER BY `id` DESC LIMIT 1'));

        // Size and number limits.
        $settings->set('support.attachment_max_mb', 1);
        $settings->set('support.attachment_max_files', 2);
        $this->openTicket([$this->uploadedFile('big.log', str_repeat("log line\n", 130000), 'text/plain')]);
        self::assertSame(1, $count(), 'Too large.');
        $this->openTicket([$this->uploadedFile('a.log', 'a', 'text/plain'), $this->uploadedFile('b.log', 'b', 'text/plain'), $this->uploadedFile('c.log', 'c', 'text/plain')]);
        self::assertSame(1, $count(), 'Too many.');
        $this->openTicket([['name' => 'x.png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'type' => 'image/png']]);
        self::assertSame(1, $count(), 'Upload errors are refused.');

        // Replies are checked the same way.
        $ticketId = (int) $this->db->fetchValue('SELECT `id` FROM `nx_tickets` WHERE `user_id` = ?', [$customer->id]);
        $before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_ticket_messages` WHERE `ticket_id` = ?', [$ticketId]);
        $this->postWithFiles('/tickets/' . $ticketId . '/reply', ['message' => 'Here is the file.'], [$this->uploadedFile('x.png', '<?php echo 1;', 'image/png')]);
        self::assertSame($before, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_ticket_messages` WHERE `ticket_id` = ?', [$ticketId]));

        // Switched off: no file field, uploads refused.
        $settings->set('support.attachments_enabled', false);
        self::assertStringNotContainsString('name="attachments[]"', $this->get('/tickets/create')->body());
        $this->openTicket([$this->uploadedFile('ok.log', 'fine', 'text/plain')]);
        self::assertSame(1, $count());
        $this->post('/tickets', ['subject' => 'Without files', 'message' => 'Tickets still work without files.', 'priority' => 'normal']);
        self::assertSame(2, $count());
    }
}
