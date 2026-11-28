<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\SshKeyFixtures as Keys;

/**
 * Account → SSH keys: validation, limits, ownership.
 */
final class SshKeyTest extends FeatureTestCase
{
    public function testCustomerAddsListsAndDeletesKeys(): void
    {
        $customer = $this->createUser();
        $this->actingAs($customer);

        $this->assertRedirect($this->post('/account/ssh-keys', ['name' => 'Laptop', 'public_key' => Keys::ED25519]), '/account/ssh-keys');
        $page = $this->get('/account/ssh-keys')->body();
        self::assertStringContainsString('Laptop', $page);
        self::assertStringContainsString(Keys::ED25519_FP, $page);
        self::assertStringContainsString('ssh-ed25519 (256 bit)', $page);
        self::assertStringContainsString('href="/account/ssh-keys"', $page, 'Navigation entry.');

        $row = $this->db->fetchOne('SELECT * FROM nx_ssh_keys WHERE user_id = ?', [$customer->id]);
        self::assertNotNull($row);
        self::assertSame(Keys::ED25519, $row['public_key']);
        self::assertSame(Keys::ED25519_FP, $row['fingerprint']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'ssh_key.added' AND actor_id = ?", [$customer->id]));

        // The same key twice is refused.
        $this->post('/account/ssh-keys', ['name' => 'Again', 'public_key' => Keys::ED25519]);
        self::assertStringContainsString('This key is already stored in your account.', $this->get('/account/ssh-keys')->body());
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_ssh_keys WHERE user_id = ?', [$customer->id]));

        $this->assertRedirect($this->post('/account/ssh-keys/' . $row['id'] . '/delete'), '/account/ssh-keys');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_ssh_keys WHERE user_id = ?', [$customer->id]));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'ssh_key.deleted' AND target_id = ?", [$row['id']]));
    }

    public function testInvalidKeysAndTheLimitAreEnforced(): void
    {
        $customer = $this->createUser();
        $this->actingAs($customer);
        $this->app->container()->get(SettingsRepository::class)->set('server.ssh_keys_max', 2);

        $this->post('/account/ssh-keys', ['name' => 'Weak', 'public_key' => Keys::RSA1024]);
        self::assertStringContainsString('RSA keys need at least 2048 bits.', $this->get('/account/ssh-keys')->body());
        $this->post('/account/ssh-keys', ['name' => 'Private', 'public_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----"]);
        $this->post('/account/ssh-keys', ['name' => "Bad\x01name", 'public_key' => Keys::ECDSA256]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_ssh_keys WHERE user_id = ?', [$customer->id]));

        $this->post('/account/ssh-keys', ['name' => 'One', 'public_key' => Keys::ECDSA256]);
        $this->post('/account/ssh-keys', ['name' => 'Two', 'public_key' => Keys::RSA2048]);
        $this->post('/account/ssh-keys', ['name' => 'Three', 'public_key' => Keys::ED25519_B]);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_ssh_keys WHERE user_id = ?', [$customer->id]));
        $page = $this->get('/account/ssh-keys')->body();
        self::assertStringContainsString('You have reached the maximum number of SSH keys.', $page);
        self::assertStringNotContainsString('action="/account/ssh-keys" novalidate', $page, 'No add form at the limit.');
    }

    public function testKeysOfOtherCustomersCannotBeSeenOrDeleted(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);
        $this->post('/account/ssh-keys', ['name' => 'Owner key', 'public_key' => Keys::ED25519]);
        $keyId = (int) $this->db->fetchValue('SELECT id FROM nx_ssh_keys WHERE user_id = ?', [$owner->id]);

        $attacker = $this->createUser();
        $this->actingAs($attacker);
        self::assertStringNotContainsString('Owner key', $this->get('/account/ssh-keys')->body());
        self::assertSame(404, $this->post('/account/ssh-keys/' . $keyId . '/delete')->status());
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_ssh_keys WHERE id = ?', [$keyId]));

        // The same public key may be stored by another account (fingerprints are unique per account).
        $this->post('/account/ssh-keys', ['name' => 'Mine too', 'public_key' => Keys::ED25519]);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_ssh_keys WHERE user_id = ?', [$attacker->id]));
    }

    public function testGuestsAreSentToTheSignIn(): void
    {
        self::assertTrue($this->get('/account/ssh-keys')->isRedirect());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_ssh_keys'));
    }
}
