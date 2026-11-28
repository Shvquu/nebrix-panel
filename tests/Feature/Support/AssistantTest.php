<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Support;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Support\HelpCenter;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;
use Psr\Http\Message\RequestInterface;

/**
 * Help center AI assistant against a fake Claude API (no real requests):
 * off by default, request format, no customer data, limits, error handling.
 */
final class AssistantTest extends FeatureTestCase
{
    private const API_KEY = 'sk-ant-test-0000000000000000';

    private FakeHttp $http;

    /** @var array<string, mixed> */
    private array $reply = ['stop_reason' => 'end_turn', 'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'x'], ['type' => 'text', 'text' => 'Open **Services** and click "Reset password".']]];

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp();
        $this->http->on('POST', '/v1/messages', fn (): Response => new Response($this->status, ['Content-Type' => 'application/json'], (string) json_encode($this->status === 200 ? $this->reply + ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'm'] : ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']])));
        $this->app->container()->set(ClientInterface::class, fn (): ClientInterface => $this->http->client());

        $help = $this->app->container()->get(HelpCenter::class);
        $category = $help->saveCategory(null, ['slug' => 'servers', 'title' => 'Servers', 'description' => null, 'sort_order' => 0, 'is_published' => true]);
        $staff = $this->createUser(Role::ADMIN);
        $help->saveArticle(null, ['category_id' => $category, 'slug' => 'reset-password', 'title' => 'Reset the server password', 'body' => 'Open Services, choose the server and click Reset password.', 'sort_order' => 0, 'is_published' => true], $staff->id);
        $help->saveArticle(null, ['category_id' => $category, 'slug' => 'draft', 'title' => 'Internal password notes', 'body' => 'Secret draft about the password policy.', 'sort_order' => 0, 'is_published' => false], $staff->id);
    }

    private function enable(): void
    {
        $settings = $this->app->container()->get(SettingsRepository::class);
        $settings->set('assistant.enabled', true);
        $settings->set('assistant.api_key', self::API_KEY);
    }

    private function customer(): User
    {
        $customer = $this->createUser(Role::CUSTOMER, ['first_name' => 'Adalinde', 'last_name' => 'Privatname', 'email' => 'adalinde.' . bin2hex(random_bytes(3)) . '@example.test']);
        $this->actingAs($customer);

        return $customer;
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(RequestInterface $request): array
    {
        return FakeHttp::json($request);
    }

    public function testTheAssistantIsOffByDefault(): void
    {
        $this->customer();
        self::assertSame(404, $this->get('/help/assistant')->status());
        self::assertSame(404, $this->post('/help/assistant', ['question' => 'How do I reset my password?'])->status());
        self::assertStringNotContainsString('/help/assistant', $this->get('/help')->body());
        self::assertSame([], $this->http->history);

        // Enabled without a key it stays off.
        $this->app->container()->get(SettingsRepository::class)->set('assistant.enabled', true);
        self::assertSame(404, $this->get('/help/assistant')->status());
    }

    public function testAnswersFromPublishedArticlesWithoutCustomerData(): void
    {
        $this->enable();
        $this->cookies = [];
        self::assertTrue($this->get('/help/assistant')->isRedirect(), 'Only for signed-in customers.');

        $customer = $this->customer();
        self::assertStringContainsString('/help/assistant', $this->get('/help')->body());
        $this->assertRedirect($this->post('/help/assistant', ['question' => 'How can I reset my server password?']), '/help/assistant');

        self::assertCount(1, $this->http->history);
        $request = $this->http->last();
        self::assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        self::assertSame(self::API_KEY, $request->getHeaderLine('x-api-key'));
        self::assertSame('2026-10-21', $request->getHeaderLine('anthropic-version'));
        self::assertSame('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $options = $this->http->history[0]['options'];
        self::assertFalse($options['allow_redirects']);
        self::assertSame(60, $options['timeout']);

        $body = $this->sentBody($request);
        self::assertSame('claude-opus-5-5', $body['model'], 'Default model from the settings.');
        self::assertSame(2000, $body['max_tokens']);
        self::assertSame(['effort' => 'low'], $body['output_config']);
        self::assertSame('default', $body['fallbacks']);
        self::assertStringContainsString('only the help center articles', $body['system']);
        self::assertStringContainsString('Never invent account data', $body['system']);
        self::assertStringContainsString('recommend opening a support ticket', $body['system']);
        self::assertStringContainsString('Answer in English', $body['system']);
        self::assertCount(1, $body['messages']);
        $content = (string) $body['messages'][0]['content'];
        self::assertStringContainsString('<question>' . "\n" . 'How can I reset my server password?', $content);
        self::assertStringContainsString('Open Services, choose the server and click Reset password.', $content);
        self::assertStringNotContainsString('Secret draft', $content, 'Drafts are never sent.');
        $raw = (string) $request->getBody();
        foreach (['Adalinde', 'Privatname', $customer->email] as $personal) {
            self::assertStringNotContainsString($personal, $raw, 'No customer data is sent.');
        }

        $page = $this->get('/help/assistant')->body();
        self::assertStringContainsString('AI-generated answer', $page);
        self::assertStringContainsString('Open **Services** and click &quot;Reset password&quot;.', $page, 'Answers are shown as escaped text.');
        self::assertStringContainsString('Answers are generated automatically by an AI', $page);
        self::assertStringContainsString('/help/servers/reset-password', $page);
        self::assertStringContainsString('/tickets/create?subject=How%20can%20I%20reset%20my%20server%20password%3F&amp;message=How%20can%20I%20reset%20my%20server%20password%3F', $page);
        self::assertStringContainsString('value="How can I reset my server password?"', $this->get('/tickets/create', ['subject' => 'How can I reset my server password?', 'message' => 'How can I reset my server password?'])->body());

        // German customers get the answer in German; the short history goes along.
        $this->db->update('nx_users', ['language' => 'de'], ['id' => $customer->id]);
        $this->post('/help/assistant', ['question' => 'Und wo finde ich das Passwort danach?']);
        $second = $this->sentBody($this->http->last());
        self::assertStringContainsString('Answer in German', $second['system']);
        self::assertCount(3, $second['messages']);
        self::assertSame(['user', 'assistant', 'user'], array_column($second['messages'], 'role'));
        self::assertSame('How can I reset my server password?', $second['messages'][0]['content']);

        // The key is a secret setting (encrypted at rest, never sent back to forms) and never logged.
        // (The system settings page itself is not opened here: its system checks end the test transaction.)
        self::assertTrue($this->app->container()->get(SettingsRepository::class)->definition('assistant.api_key')['secret'] ?? false);
        $stored = (string) $this->db->fetchValue("SELECT `value` FROM `nx_settings` WHERE `key` = 'assistant.api_key'");
        self::assertStringNotContainsString(self::API_KEY, $stored);
        self::assertStringNotContainsString(self::API_KEY, (string) @file_get_contents(dirname(__DIR__, 3) . '/storage/logs/test.log'));
    }

    public function testLimitsAndFailures(): void
    {
        $this->enable();
        $settings = $this->app->container()->get(SettingsRepository::class);
        $settings->set('assistant.hourly_limit', 2);
        $settings->set('assistant.max_question_chars', 60);
        $settings->set('assistant.max_tokens', 300);
        $this->customer();

        // Too long: refused before any request.
        $this->post('/help/assistant', ['question' => str_repeat('password ', 10)]);
        self::assertSame([], $this->http->history);

        // No matching article: answered locally, no request.
        $this->post('/help/assistant', ['question' => 'Do you sell kubernetes clusters?']);
        self::assertSame([], $this->http->history);
        self::assertStringContainsString('I could not find an answer to this question in our help center.', $this->get('/help/assistant')->body());

        // The provider fails: friendly message, nothing invented.
        $this->status = 529;
        $this->post('/help/assistant', ['question' => 'How do I reset the password?']);
        self::assertCount(1, $this->http->history);
        self::assertSame(300, $this->sentBody($this->http->last())['max_tokens']);
        self::assertStringContainsString('The assistant is not available right now.', $this->get('/help/assistant')->body());

        // Hourly limit per customer (each question counts).
        $this->status = 200;
        $this->post('/help/assistant', ['question' => 'How do I reset the password?']);
        self::assertCount(1, $this->http->history, 'The third question within the hour is refused.');
        self::assertStringContainsString('You have asked many questions in a short time.', $this->get('/help/assistant')->body());

        // Daily cap for all customers together.
        $settings->set('assistant.hourly_limit', 100);
        $settings->set('assistant.global_daily_limit', 1);
        $this->customer();
        $this->post('/help/assistant', ['question' => 'How do I reset the password?']);
        self::assertCount(1, $this->http->history);
        self::assertStringContainsString('The assistant has reached its limit for today.', $this->get('/help/assistant')->body());

        // A refusal is not shown as an answer.
        $settings->set('assistant.global_daily_limit', 100);
        $this->reply = ['stop_reason' => 'refusal', 'content' => []];
        $this->customer();
        $this->post('/help/assistant', ['question' => 'How do I reset the password?']);
        self::assertCount(2, $this->http->history);
        $page = $this->get('/help/assistant')->body();
        self::assertStringNotContainsString('AI-generated answer', $page);
        self::assertStringContainsString('Create ticket', $page);

        // Clearing the conversation.
        $this->post('/help/assistant/clear');
        self::assertStringContainsString('Ask a question about our services', $this->get('/help/assistant')->body());
    }
}
