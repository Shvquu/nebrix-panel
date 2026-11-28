<?php

declare(strict_types=1);

namespace Nebrix\Controller\Account;

use Nebrix\Http\Controller;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Marketing\Consents;
use Nebrix\Marketing\Newsletter;
use Nebrix\Marketing\ReviewInvitations;
use Nebrix\Settings\SettingsRepository;
use Nebrix\View\Translator;

/**
 * E-mail preferences of the customer (Account → Communication): the
 * newsletter and the consent to review invitations. Both can be switched
 * on and off at any time; each change is recorded with time and shortened
 * IP address.
 */
final class CommunicationController extends Controller
{
    public function __construct(
        private readonly Newsletter $newsletter,
        private readonly Consents $consents,
        private readonly ReviewInvitations $reviews,
        private readonly SettingsRepository $settings,
        private readonly Translator $translator,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $subscription = $this->newsletter->forUser($user);
        $consent = $this->consents->find($user->id, Consents::REVIEW_INVITATION);

        return $this->render($request, 'account/communication.twig', [
            'newsletter_available' => $this->newsletter->enabled() || ($subscription['status'] ?? null) === Newsletter::CONFIRMED,
            'subscription' => $subscription,
            'reviews_available' => $this->reviews->enabled() || $this->consents->has($user->id, Consents::REVIEW_INVITATION),
            'consent' => $consent !== null && $consent['granted_at'] !== null && $consent['withdrawn_at'] === null ? $consent : null,
            'newsletter_text' => $this->newsletterText(),
            'review_text' => $this->reviews->consentText(),
        ]);
    }

    public function update(Request $request): Response
    {
        $user = $this->user($request);
        $data = $this->validate($request, ['newsletter' => 'bool', 'review_invitations' => 'bool', 'newsletter_shown' => 'bool', 'reviews_shown' => 'bool']);
        $ip = $request->clientIp();
        $pending = false;

        // Only options that were shown are changed (a hidden field marks them).
        if ($data['newsletter_shown'] === true) {
            if ($data['newsletter'] === true && !$this->newsletter->isSubscribed($user) && $this->newsletter->enabled()) {
                $pending = !$this->newsletter->subscribeAccount($user, $ip, $this->newsletterText());
            } elseif ($data['newsletter'] !== true && $this->newsletter->isSubscribed($user)) {
                $this->newsletter->unsubscribeAccount($user, $ip);
            }
        }
        if ($data['reviews_shown'] === true) {
            $has = $this->consents->has($user->id, Consents::REVIEW_INVITATION);
            if ($data['review_invitations'] === true && !$has && $this->reviews->enabled()) {
                $this->consents->grant($user->id, Consents::REVIEW_INVITATION, 'account', $ip, $this->reviews->consentText());
            } elseif ($data['review_invitations'] !== true && $has) {
                $this->consents->withdraw($user->id, Consents::REVIEW_INVITATION, 'account', $ip);
            }
        }

        $this->flash($request, 'success', $pending ? 'Please confirm your newsletter subscription with the link we sent you.' : 'Your e-mail preferences have been saved.');

        return $this->redirect('/account/communication');
    }

    private function newsletterText(): string
    {
        return $this->translator->translate('I would like to receive the newsletter of :brand with news and offers by e-mail. I can unsubscribe at any time with the link in every newsletter.', ['brand' => $this->settings->string('general.company_name')]);
    }
}
