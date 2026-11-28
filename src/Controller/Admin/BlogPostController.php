<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Controller\Marketing\BlogController as PublicBlog;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Marketing\BlogPosts;
use Nebrix\Marketing\MediaStore;
use Nebrix\Security\ValidationException;
use Nebrix\Security\Validator;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Blog posts (Marketing → Blog). Viewing and the preview of drafts and
 * scheduled posts need content.view, changes content.manage; changes are
 * audit-logged. A publication date in the future schedules a post.
 */
final class BlogPostController extends Controller
{
    public function __construct(
        private readonly BlogPosts $posts,
        private readonly MediaStore $media,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        $page = $this->page($request);
        $result = $this->posts->all($page);
        foreach ($result['rows'] as $key => $post) {
            $result['rows'][$key]['public'] = $this->posts->isPublic($post);
        }

        return $this->render($request, 'admin/marketing/blog/index.twig', [
            'posts' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => 25,
            'blog_enabled' => $this->settings->bool('blog.enabled'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize($request, Permission::CONTENT_MANAGE);

        return $this->form($request, null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->posts->find($id) ?? throw HttpException::notFound());
    }

    public function store(Request $request): Response
    {
        $this->authorize($request, Permission::CONTENT_MANAGE);
        $data = $this->input($request, 0);
        $cover = $this->uploadedCover($request);
        $id = $this->posts->save(null, $data, $this->user($request)->id, $cover !== null ? ['cover_image' => $cover] : []);
        $this->audit($request, 'blog_post.created', 'blog_post', $id, ['slug' => $data['slug'], 'status' => $data['status'], 'published_at' => $data['published_at']]);
        $this->flash($request, 'success', 'Post saved.');

        return $this->redirect('/admin/blog/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::CONTENT_MANAGE);
        $post = $this->posts->find($id) ?? throw HttpException::notFound();
        $data = $this->input($request, $id);
        $cover = $this->uploadedCover($request);
        $extra = [];
        if ($cover !== null || $request->string('remove_cover') === '1') {
            $extra['cover_image'] = $cover;
        }
        $this->posts->save($id, $data, $this->user($request)->id, $extra);
        if (array_key_exists('cover_image', $extra) && $post['cover_image'] !== null) {
            $this->media->delete((string) $post['cover_image']);
        }
        $this->audit($request, 'blog_post.updated', 'blog_post', $id, ['slug' => $data['slug'], 'status' => $data['status'], 'published_at' => $data['published_at']]);
        $this->flash($request, 'success', 'Post saved.');

        return $this->redirect('/admin/blog/' . $id);
    }

    public function delete(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::CONTENT_MANAGE);
        $post = $this->posts->find($id) ?? throw HttpException::notFound();
        $this->posts->delete($id);
        $this->media->delete($post['cover_image'] !== null ? (string) $post['cover_image'] : null);
        $this->audit($request, 'blog_post.deleted', 'blog_post', $id, ['slug' => $post['slug']]);
        $this->flash($request, 'success', 'Post deleted.');

        return $this->redirect('/admin/blog');
    }

    /** Staff preview of a post in any state (not indexed, no canonical address). */
    public function preview(Request $request, int $id): Response
    {
        $post = $this->posts->find($id) ?? throw HttpException::notFound();
        $public = $this->container->get(PublicBlog::class);
        $public->setContainer($this->container);

        return $public->present($request, $post, true);
    }

    /**
     * @param array<string, mixed>|null $post
     */
    private function form(Request $request, ?array $post): Response
    {
        return $this->render($request, 'admin/marketing/blog/form.twig', [
            'post' => $post,
            'published_at' => $post !== null && $post['published_at'] !== null ? $this->toLocal((string) $post['published_at']) : '',
            'state' => $post === null ? null : ($this->posts->isPublic($post) ? 'public' : ($post['status'] === BlogPosts::PUBLISHED ? 'scheduled' : 'draft')),
            'default_author' => $this->user($request)->displayName(),
            'blog_enabled' => $this->settings->bool('blog.enabled'),
        ]);
    }

    /**
     * @return array{slug: string, title: string, teaser: ?string, body: string, author_name: ?string, status: string, published_at: ?string}
     */
    private function input(Request $request, int $id): array
    {
        $data = $this->validate($request, [
            'title' => 'required|string|max:150',
            'slug' => 'required|string|max:120|slug',
            'teaser' => 'nullable|string|max:500',
            'body' => 'required|string|max:100000',
            'author_name' => 'nullable|string|max:100',
            'status' => 'required|in:' . BlogPosts::DRAFT . ',' . BlogPosts::PUBLISHED,
            'published_at' => 'nullable|string|max:20|regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/',
        ], ['slug' => 'Address', 'body' => 'Text', 'published_at' => 'Publication date']);

        if ($this->posts->slugExists((string) $data['slug'], $id)) {
            throw new ValidationException(['slug' => ['Another post already uses this address.']], Validator::safeInput($request->all()));
        }
        $publishedAt = null;
        if (is_string($data['published_at']) && $data['published_at'] !== '') {
            $publishedAt = $this->toUtc($data['published_at']) ?? throw new ValidationException(['published_at' => ['Enter a valid date and time.']], Validator::safeInput($request->all()));
        }
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return [
            'slug' => (string) $data['slug'],
            'title' => trim((string) $data['title']),
            'teaser' => $text($data['teaser']),
            'body' => (string) $data['body'],
            'author_name' => $text($data['author_name']),
            'status' => (string) $data['status'],
            'published_at' => $publishedAt,
        ];
    }

    /** Stores an uploaded cover image (validated by content), or null without a file. */
    private function uploadedCover(Request $request): ?string
    {
        $file = $request->file('cover');
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        try {
            return $this->media->store($file);
        } catch (RuntimeException $exception) {
            $this->logger->info('Blog cover image rejected', ['reason' => $exception->getMessage()]);

            throw new ValidationException(['cover' => [$exception->getMessage()]], Validator::safeInput($request->all()));
        }
    }

    private function toUtc(string $local): ?string
    {
        try {
            $time = new \DateTimeImmutable($local, $this->zone());
        } catch (\Exception) {
            return null;
        }

        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function toLocal(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($this->zone())->format('Y-m-d\TH:i');
    }

    private function zone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->settings->string('general.timezone') ?: 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
