<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Http\Controller;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Marketing\MediaStore;
use Nebrix\Security\ValidationException;
use Nebrix\Settings\SettingsRepository;
use RuntimeException;

/**
 * The preview image for social networks (og:image / twitter:image) on
 * Admin → Settings → Marketing (permission settings.branding,
 * audit-logged). PNG or JPEG, validated by content.
 */
final class SeoImageController extends Controller
{
    public function __construct(
        private readonly MediaStore $media,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function upload(Request $request): Response
    {
        $this->authorize($request, Permission::SETTINGS_BRANDING);
        try {
            $name = $this->media->store($request->file('file') ?? []);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['og_image' => $exception->getMessage()]);
        }
        $previous = $this->settings->string('seo.og_image');
        $this->settings->set('seo.og_image', $name, $this->user($request)->id);
        if ($previous !== '') {
            $this->media->delete($previous);
        }
        $this->audit($request, 'settings.og_image_uploaded', 'settings', 'seo');
        $this->flash($request, 'success', 'Preview image uploaded.');

        return $this->redirect('/admin/settings/marketing');
    }

    public function remove(Request $request): Response
    {
        $this->authorize($request, Permission::SETTINGS_BRANDING);
        $this->media->delete($this->settings->string('seo.og_image'));
        $this->settings->set('seo.og_image', '', $this->user($request)->id);
        $this->audit($request, 'settings.og_image_removed', 'settings', 'seo');
        $this->flash($request, 'success', 'Preview image removed.');

        return $this->redirect('/admin/settings/marketing');
    }
}
