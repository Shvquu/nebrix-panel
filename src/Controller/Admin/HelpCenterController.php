<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\ValidationException;
use Nebrix\Support\HelpCenter;
use Nebrix\Support\Markdown;
use Twig\Markup;

/**
 * Admin → Help center: categories and articles (Markdown, published or
 * draft). All routes require help_center.manage; changes are audit-logged.
 */
final class HelpCenterController extends Controller
{
    public function __construct(private readonly HelpCenter $help)
    {
    }

    public function index(Request $request): Response
    {
        $category = $request->string('category');

        return $this->render($request, 'admin/help/index.twig', [
            'categories' => $this->help->allCategories(),
            'articles' => $this->help->allArticles(ctype_digit($category) ? (int) $category : null, mb_substr($request->string('search'), 0, 100)),
            'filter_category' => ctype_digit($category) ? (int) $category : null,
            'search' => mb_substr($request->string('search'), 0, 100),
        ]);
    }

    public function createCategory(Request $request): Response
    {
        return $this->render($request, 'admin/help/category_form.twig', ['category' => null]);
    }

    public function editCategory(Request $request, int $id): Response
    {
        return $this->render($request, 'admin/help/category_form.twig', ['category' => $this->help->findCategory($id) ?? throw HttpException::notFound()]);
    }

    public function storeCategory(Request $request): Response
    {
        $id = $this->help->saveCategory(null, $this->categoryInput($request, null));
        $this->audit($request, 'help.category_created', 'help_category', $id);
        $this->flash($request, 'success', 'Category saved.');

        return $this->redirect('/admin/help');
    }

    public function updateCategory(Request $request, int $id): Response
    {
        $this->help->findCategory($id) ?? throw HttpException::notFound();
        $this->help->saveCategory($id, $this->categoryInput($request, $id));
        $this->audit($request, 'help.category_updated', 'help_category', $id);
        $this->flash($request, 'success', 'Category saved.');

        return $this->redirect('/admin/help');
    }

    public function deleteCategory(Request $request, int $id): Response
    {
        $this->help->findCategory($id) ?? throw HttpException::notFound();
        if (!$this->help->deleteCategory($id)) {
            $this->flash($request, 'error', 'Move or delete the articles of this category first.');

            return $this->redirect('/admin/help/categories/' . $id);
        }
        $this->audit($request, 'help.category_deleted', 'help_category', $id);
        $this->flash($request, 'success', 'Category deleted.');

        return $this->redirect('/admin/help');
    }

    public function createArticle(Request $request): Response
    {
        $category = $request->string('category');

        return $this->articleForm($request, null, ctype_digit($category) ? (int) $category : null);
    }

    public function editArticle(Request $request, int $id): Response
    {
        return $this->articleForm($request, $this->help->findArticle($id) ?? throw HttpException::notFound(), null);
    }

    public function storeArticle(Request $request): Response
    {
        $id = $this->help->saveArticle(null, $this->articleInput($request, null), $this->user($request)->id);
        $this->audit($request, 'help.article_created', 'help_article', $id);
        $this->flash($request, 'success', 'Article saved.');

        return $this->redirect('/admin/help/articles/' . $id);
    }

    public function updateArticle(Request $request, int $id): Response
    {
        $this->help->findArticle($id) ?? throw HttpException::notFound();
        $data = $this->articleInput($request, $id);
        $this->help->saveArticle($id, $data, $this->user($request)->id);
        $this->audit($request, 'help.article_updated', 'help_article', $id, ['published' => $data['is_published']]);
        $this->flash($request, 'success', 'Article saved.');

        return $this->redirect('/admin/help/articles/' . $id);
    }

    public function deleteArticle(Request $request, int $id): Response
    {
        $article = $this->help->findArticle($id) ?? throw HttpException::notFound();
        $this->help->deleteArticle($id);
        $this->audit($request, 'help.article_deleted', 'help_article', $id, ['title' => mb_substr((string) $article['title'], 0, 100)]);
        $this->flash($request, 'success', 'Article deleted.');

        return $this->redirect('/admin/help');
    }

    /**
     * @param array<string, mixed>|null $article
     */
    private function articleForm(Request $request, ?array $article, ?int $categoryId): Response
    {
        $categories = [];
        foreach ($this->help->allCategories() as $category) {
            $categories[(string) $category['id']] = (string) $category['title'];
        }

        return $this->render($request, 'admin/help/article_form.twig', [
            'article' => $article,
            'categories' => $categories,
            'category_id' => $article['category_id'] ?? $categoryId,
            // Preview with the same renderer as the public page (all HTML escaped).
            'preview' => $article !== null ? new Markup(Markdown::render((string) $article['body']), 'UTF-8') : null,
        ]);
    }

    /**
     * @return array{slug: string, title: string, description: ?string, sort_order: int, is_published: bool}
     */
    private function categoryInput(Request $request, ?int $id): array
    {
        $data = $this->validate($request, [
            'title' => 'required|string|max:150',
            'slug' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:255',
            'sort_order' => 'nullable|int|min:-10000|max:10000',
            'is_published' => 'bool',
        ]);
        $slug = HelpCenter::slugify((string) (($data['slug'] ?? '') !== '' ? $data['slug'] : $data['title']));
        if (in_array($slug, HelpCenter::RESERVED_SLUGS, true)) {
            throw ValidationException::withMessages(['slug' => 'This address is reserved. Please choose another one.']);
        }
        if ($this->help->categorySlugTaken($slug, $id)) {
            throw ValidationException::withMessages(['slug' => 'Another category already uses this address.']);
        }

        return [
            'slug' => $slug,
            'title' => trim((string) $data['title']),
            'description' => $data['description'] !== null ? trim((string) $data['description']) : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_published' => (bool) $data['is_published'],
        ];
    }

    /**
     * @return array{category_id: int, slug: string, title: string, body: string, sort_order: int, is_published: bool}
     */
    private function articleInput(Request $request, ?int $id): array
    {
        $data = $this->validate($request, [
            'category_id' => 'required|int|min:1',
            'title' => 'required|string|max:200',
            'slug' => 'nullable|string|max:120',
            'body' => 'required|string|max:100000',
            'sort_order' => 'nullable|int|min:-10000|max:10000',
            'is_published' => 'bool',
        ]);
        $categoryId = (int) $data['category_id'];
        if ($this->help->findCategory($categoryId) === null) {
            throw ValidationException::withMessages(['category_id' => 'Please choose a category.']);
        }
        $slug = HelpCenter::slugify((string) (($data['slug'] ?? '') !== '' ? $data['slug'] : $data['title']));
        if ($this->help->articleSlugTaken($categoryId, $slug, $id)) {
            throw ValidationException::withMessages(['slug' => 'Another article in this category already uses this address.']);
        }

        return [
            'category_id' => $categoryId,
            'slug' => $slug,
            'title' => trim((string) $data['title']),
            'body' => (string) $data['body'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_published' => (bool) $data['is_published'],
        ];
    }
}
