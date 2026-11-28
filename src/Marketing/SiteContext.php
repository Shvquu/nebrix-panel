<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Nebrix\Settings\SettingsRepository;
use Nebrix\Website\WebsiteCatalog;

/**
 * What the website layout needs on the pages of the marketing area
 * (landing pages, blog, newsletter) – the same navigation, sale banner and
 * footer as the pages of WebsiteController – and the marketing additions
 * of those pages (SEO data, latest posts on the start page).
 */
class SiteContext
{
    public function __construct(
        private readonly WebsiteCatalog $catalog,
        private readonly SettingsRepository $settings,
        private readonly Seo $seo,
        private readonly BlogPosts $posts,
    ) {
    }

    public function websiteEnabled(): bool
    {
        return $this->settings->bool('website.enabled');
    }

    public function blogEnabled(): bool
    {
        return $this->websiteEnabled() && $this->settings->bool('blog.enabled');
    }

    /**
     * Layout variables of layouts/website.twig.
     *
     * @return array<string, mixed>
     */
    public function layout(?WebsiteCatalog $catalog = null): array
    {
        $catalog ??= $this->catalog->forRequest();

        return [
            'nav_pages' => $catalog->pages(),
            'nav_menu' => $catalog->menu(),
            'site_sale' => $catalog->bannerSale(),
            'about_page' => trim($this->settings->string('website.about_text')) !== '',
            'small_business' => $catalog->smallBusiness(),
            'trustpilot' => null,
        ];
    }

    /**
     * Additions for the pages of WebsiteController.
     *
     * @param array<string, mixed> $context
     * @return array{seo: array<string, mixed>}
     */
    public function website(string $template, array $context): array
    {
        return ['seo' => $this->seo->forWebsite($template, $context)];
    }

    /**
     * Latest public posts for the start page (setting blog.home_posts).
     *
     * @return list<array<string, mixed>>
     */
    public function latestPosts(): array
    {
        return $this->blogEnabled() ? $this->posts->latest($this->settings->int('blog.home_posts')) : [];
    }
}
