<?php

declare(strict_types=1);

namespace Nebrix\Controller\Account;

use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Payment\AutopayAttempts;
use Nebrix\Payment\PaymentMethodRepository;
use Nebrix\Payment\Stripe\StripeGateway;
use Nebrix\Security\RateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Saved payment methods (card / SEPA mandate at Stripe) and the switch for
 * automatic renewal payments. Methods are always looked up for the signed-in
 * customer; the setup result is verified with Stripe server-side.
 */
final class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PaymentMethodRepository $methods,
        private readonly AutopayAttempts $attempts,
        private readonly RateLimiter $limiter,
        private readonly Connection $db,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);

        return $this->render($request, 'account/payment_methods.twig', [
            'methods' => $this->methods->forUser($user->id),
            'auto_pay_saved' => (int) $this->db->fetchValue('SELECT `auto_pay_saved` FROM `nx_users` WHERE `id` = ?', [$user->id]) === 1,
            'sepa' => in_array('sepa_debit', $this->stripe->savedMethodTypes(), true),
            'attempts' => $this->attempts->recentFor($user->id),
        ]);
    }

    public function add(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        if (!$this->limiter->attempt('payment-method-setup:' . $user->id, 10, 3600)) {
            throw HttpException::tooManyRequests();
        }
        if (count($this->methods->forUser($user->id)) >= 5) {
            $this->flash($request, 'error', 'You can save up to five payment methods. Please remove one first.');

            return $this->redirect('/account/payment-methods');
        }

        $base = rtrim($this->config->string('app.url'), '/');
        try {
            $url = $this->stripe->startSetup($user, $base . '/account/payment-methods/return', $base . '/account/payment-methods?cancelled=1');
        } catch (Throwable $exception) {
            $this->logger->error('Payment method setup failed', ['user' => $user->id, 'message' => $exception->getMessage()]);
            $this->flash($request, 'error', 'The payment provider is currently not available. Please try again later.');

            return $this->redirect('/account/payment-methods');
        }

        // External setup page of Stripe (validated https URL).
        return Response::redirect($url, 303);
    }

    public function complete(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        if (!$this->limiter->attempt('payment-method-return:' . $user->id, 30, 3600)) {
            throw HttpException::tooManyRequests();
        }

        if ($this->stripe->completeSetup($user, $request->string('session_id')) === null) {
            $this->flash($request, 'error', 'The payment method could not be confirmed. If you completed the form at Stripe, it will appear here shortly.');
        } else {
            $this->audit($request, 'payment_method.added', 'user', $user->id);
            $this->flash($request, 'success', 'Your payment method has been saved.');
        }

        return $this->redirect('/account/payment-methods');
    }

    public function makeDefault(Request $request, int $id): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        if (!$this->methods->makeDefault($user->id, $id)) {
            throw HttpException::notFound();
        }
        $this->flash($request, 'success', 'The default payment method has been changed.');

        return $this->redirect('/account/payment-methods');
    }

    public function remove(Request $request, int $id): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        $method = $this->methods->find($user->id, $id);
        if ($method === null) {
            throw HttpException::notFound();
        }
        if (!$this->stripe->detach($method)) {
            $this->flash($request, 'error', 'The payment method could not be removed at the payment provider. Please try again later.');

            return $this->redirect('/account/payment-methods');
        }
        if ($this->methods->defaultFor($user->id) === null) {
            $this->db->update('nx_users', ['auto_pay_saved' => 0], ['id' => $user->id]);
        }
        $this->audit($request, 'payment_method.removed', 'user', $user->id, ['type' => $method['type']]);
        $this->flash($request, 'success', 'The payment method has been removed.');

        return $this->redirect('/account/payment-methods');
    }

    public function autopay(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        $enable = $request->input('auto_pay_saved') !== null;
        if ($enable && $this->methods->defaultFor($user->id) === null) {
            $this->flash($request, 'error', 'Please save a payment method first.');

            return $this->redirect('/account/payment-methods');
        }
        $this->db->update('nx_users', ['auto_pay_saved' => $enable ? 1 : 0], ['id' => $user->id]);
        $this->audit($request, $enable ? 'payment_method.autopay_on' : 'payment_method.autopay_off', 'user', $user->id);
        $this->flash($request, 'success', $enable ? 'Renewal invoices are now paid automatically.' : 'Automatic payment has been switched off.');

        return $this->redirect('/account/payment-methods');
    }

    private function ensureEnabled(): void
    {
        if (!$this->stripe->savedMethodsEnabled()) {
            throw HttpException::notFound();
        }
    }
}
