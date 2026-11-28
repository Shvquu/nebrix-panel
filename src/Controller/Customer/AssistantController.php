<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Support\Assistant\AssistantException;
use Nebrix\Support\Assistant\HelpAssistant;
use Nebrix\View\Translator;
use Nebrix\Website\WebsiteCatalog;

/**
 * AI assistant of the help center for signed-in customers (optional, off
 * by default). Answers come from the published help articles; every answer
 * is marked as AI-generated and offers a ticket prefilled with the question.
 */
final class AssistantController extends Controller
{
    public function __construct(
        private readonly HelpAssistant $assistant,
        private readonly SettingsRepository $settings,
        private readonly WebsiteCatalog $catalog,
        private readonly Translator $translator,
    ) {
    }

    public function show(Request $request): Response
    {
        $this->ensureEnabled();
        $catalog = $this->catalog->forRequest();

        return $this->render($request, 'help/assistant.twig', [
            'history' => $this->assistant->history($request->session()),
            'max_length' => $this->assistant->maxQuestionLength(),
            'tickets_enabled' => $this->settings->bool('system.tickets_enabled'),
            'nav_pages' => $catalog->pages(),
            'nav_menu' => $catalog->menu(),
            'site_sale' => $catalog->bannerSale(),
            'about_page' => trim($this->settings->string('website.about_text')) !== '',
            'small_business' => $catalog->smallBusiness(),
            'trustpilot' => null,
            'assistant_enabled' => true,
        ]);
    }

    public function ask(Request $request): Response
    {
        $this->ensureEnabled();
        $data = $this->validate($request, ['question' => 'required|string|min:3|max:' . $this->assistant->maxQuestionLength()]);

        try {
            $this->assistant->ask($this->user($request), $request->session(), (string) $data['question'], $this->translator->locale());
        } catch (AssistantException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/help/assistant');
    }

    public function clear(Request $request): Response
    {
        $this->ensureEnabled();
        $this->assistant->clear($request->session());

        return $this->redirect('/help/assistant');
    }

    private function ensureEnabled(): void
    {
        if (!$this->assistant->enabled()) {
            throw HttpException::notFound();
        }
    }
}
