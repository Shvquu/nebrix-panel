<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Support\CannedResponses;

/**
 * Admin → Canned responses: prepared ticket answers with placeholders.
 * All routes require canned_responses.manage; changes are audit-logged.
 */
final class CannedResponseController extends Controller
{
    public function __construct(private readonly CannedResponses $canned)
    {
    }

    public function index(Request $request): Response
    {
        return $this->render($request, 'admin/canned_responses/index.twig', ['responses' => $this->canned->all(), 'placeholders' => CannedResponses::PLACEHOLDERS]);
    }

    public function create(Request $request): Response
    {
        return $this->render($request, 'admin/canned_responses/form.twig', ['response' => null, 'placeholders' => CannedResponses::PLACEHOLDERS]);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->render($request, 'admin/canned_responses/form.twig', ['response' => $this->canned->find($id) ?? throw HttpException::notFound(), 'placeholders' => CannedResponses::PLACEHOLDERS]);
    }

    public function store(Request $request): Response
    {
        $id = $this->canned->save(null, $this->input($request), $this->user($request)->id);
        $this->audit($request, 'canned_response.created', 'canned_response', $id);
        $this->flash($request, 'success', 'Canned response saved.');

        return $this->redirect('/admin/canned-responses');
    }

    public function update(Request $request, int $id): Response
    {
        $this->canned->find($id) ?? throw HttpException::notFound();
        $this->canned->save($id, $this->input($request), $this->user($request)->id);
        $this->audit($request, 'canned_response.updated', 'canned_response', $id);
        $this->flash($request, 'success', 'Canned response saved.');

        return $this->redirect('/admin/canned-responses');
    }

    public function delete(Request $request, int $id): Response
    {
        $this->canned->find($id) ?? throw HttpException::notFound();
        $this->canned->delete($id);
        $this->audit($request, 'canned_response.deleted', 'canned_response', $id);
        $this->flash($request, 'success', 'Canned response deleted.');

        return $this->redirect('/admin/canned-responses');
    }

    /**
     * @return array{title: string, body: string, sort_order: int}
     */
    private function input(Request $request): array
    {
        $data = $this->validate($request, [
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:20000',
            'sort_order' => 'nullable|int|min:-10000|max:10000',
        ]);

        return ['title' => trim((string) $data['title']), 'body' => (string) $data['body'], 'sort_order' => (int) ($data['sort_order'] ?? 0)];
    }
}
