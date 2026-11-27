<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Database\Connection;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\ValidationException;
use Nebrix\Status\StatusService;

/**
 * Status page management (permission status.manage): components,
 * incidents and maintenance announcements with timeline updates.
 */
final class StatusController extends Controller
{
    private const LOCAL_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/';

    public function __construct(
        private readonly StatusService $status,
        private readonly Connection $db,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request, 'admin/status/index.twig', [
            'components' => $this->status->components(false),
            'incidents' => $this->status->allIncidents(),
        ]);
    }

    public function componentForm(Request $request, ?int $id = null): Response
    {
        $component = $id !== null ? ($this->status->findComponent($id) ?? throw HttpException::notFound()) : null;

        return $this->render($request, 'admin/status/component.twig', [
            'component' => $component,
            'integrations' => $this->integrations(),
            'states' => StatusService::COMPONENT_STATES,
        ]);
    }

    public function editComponent(Request $request, int $id): Response
    {
        return $this->componentForm($request, $id);
    }

    public function saveComponent(Request $request, ?int $id = null): Response
    {
        if ($id !== null) {
            $this->status->findComponent($id) ?? throw HttpException::notFound();
        }
        $data = $this->validate($request, [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'group_name' => 'nullable|string|max:60',
            'status' => 'required|in:' . implode(',', StatusService::COMPONENT_STATES),
            'integration_id' => 'nullable|int|min:1',
            'is_visible' => 'bool',
            'sort_order' => 'nullable|int|min:-1000|max:1000',
        ]);
        if ($data['integration_id'] !== null && !array_key_exists((string) $data['integration_id'], $this->integrations())) {
            throw ValidationException::withMessages(['integration_id' => 'Choose an existing integration.']);
        }
        $this->status->saveComponent($id, [
            'name' => (string) $data['name'],
            'description' => $data['description'] !== null ? (string) $data['description'] : null,
            'group_name' => $data['group_name'] !== null ? (string) $data['group_name'] : null,
            'status' => (string) $data['status'],
            'integration_id' => $data['integration_id'] !== null ? (int) $data['integration_id'] : null,
            'is_visible' => $data['is_visible'] === true,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ], $this->user($request)->id, $request->clientIp());
        $this->flash($request, 'success', 'Component saved.');

        return $this->redirect('/admin/status');
    }

    public function updateComponent(Request $request, int $id): Response
    {
        return $this->saveComponent($request, $id);
    }

    public function deleteComponent(Request $request, int $id): Response
    {
        $this->status->findComponent($id) ?? throw HttpException::notFound();
        $this->status->deleteComponent($id, $this->user($request)->id, $request->clientIp());
        $this->flash($request, 'success', 'Component deleted.');

        return $this->redirect('/admin/status');
    }

    public function createIncident(Request $request): Response
    {
        $type = $request->string('type') === StatusService::PLANNED ? StatusService::PLANNED : StatusService::INCIDENT;

        return $this->render($request, 'admin/status/incident.twig', [
            'incident' => null,
            'type' => $type,
            'components' => $this->status->components(false),
            'starts_at' => '',
            'ends_at' => '',
        ]);
    }

    public function storeIncident(Request $request): Response
    {
        $type = $request->input('type') === StatusService::PLANNED ? StatusService::PLANNED : StatusService::INCIDENT;
        $states = $type === StatusService::PLANNED ? StatusService::MAINTENANCE_STATES : StatusService::INCIDENT_STATES;
        $data = $this->validate($request, [
            'title' => 'required|string|max:150',
            'impact' => 'nullable|in:' . implode(',', StatusService::IMPACTS),
            'status' => 'required|in:' . implode(',', $states),
            'starts_at' => ($type === StatusService::PLANNED ? 'required' : 'nullable') . '|string|regex:' . self::LOCAL_TIME,
            'ends_at' => 'nullable|string|regex:' . self::LOCAL_TIME,
            'component_ids' => 'nullable|array',
            'message' => 'required|string|max:5000',
            'is_published' => 'bool',
            'notify' => 'bool',
        ]);
        [$startsAt, $endsAt] = $this->window($data['starts_at'] ?? null, $data['ends_at'] ?? null);

        $id = $this->status->create([
            'type' => $type,
            'title' => (string) $data['title'],
            'impact' => (string) ($data['impact'] ?? 'minor'),
            'status' => (string) $data['status'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_published' => $data['is_published'] === true,
            'component_ids' => $this->componentIds($data['component_ids'] ?? []),
            'message' => (string) $data['message'],
        ], $this->user($request)->id, $request->clientIp(), $data['notify'] === true);
        $this->flash($request, 'success', $type === StatusService::PLANNED ? 'The maintenance has been announced.' : 'The incident has been posted.');

        return $this->redirect('/admin/status/incidents/' . $id);
    }

    public function showIncident(Request $request, int $id): Response
    {
        $incident = $this->status->findIncident($id) ?? throw HttpException::notFound();

        return $this->render($request, 'admin/status/incident.twig', [
            'incident' => $incident,
            'type' => (string) $incident['type'],
            'components' => $this->status->components(false),
            'starts_at' => $this->status->toLocalInput((string) $incident['starts_at']),
            'ends_at' => $this->status->toLocalInput($incident['ends_at'] !== null ? (string) $incident['ends_at'] : null),
        ]);
    }

    public function updateIncident(Request $request, int $id): Response
    {
        $incident = $this->status->findIncident($id) ?? throw HttpException::notFound();
        $data = $this->validate($request, [
            'title' => 'required|string|max:150',
            'impact' => 'nullable|in:' . implode(',', StatusService::IMPACTS),
            'starts_at' => 'required|string|regex:' . self::LOCAL_TIME,
            'ends_at' => 'nullable|string|regex:' . self::LOCAL_TIME,
            'component_ids' => 'nullable|array',
            'is_published' => 'bool',
        ]);
        [$startsAt, $endsAt] = $this->window($data['starts_at'], $data['ends_at'] ?? null);
        $this->status->update($id, [
            'title' => (string) $data['title'],
            'impact' => (string) ($data['impact'] ?? $incident['impact']),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_published' => $data['is_published'] === true,
            'component_ids' => $this->componentIds($data['component_ids'] ?? []),
        ], $this->user($request)->id, $request->clientIp());
        $this->flash($request, 'success', 'Saved.');

        return $this->redirect('/admin/status/incidents/' . $id);
    }

    public function postUpdate(Request $request, int $id): Response
    {
        $incident = $this->status->findIncident($id) ?? throw HttpException::notFound();
        $states = $incident['type'] === StatusService::PLANNED ? StatusService::MAINTENANCE_STATES : StatusService::INCIDENT_STATES;
        $data = $this->validate($request, [
            'status' => 'required|in:' . implode(',', $states),
            'message' => 'required|string|max:5000',
            'notify' => 'bool',
        ]);
        $this->status->addUpdate($id, (string) $data['status'], (string) $data['message'], $this->user($request)->id, $request->clientIp(), $data['notify'] === true);
        $this->flash($request, 'success', 'The update has been posted.');

        return $this->redirect('/admin/status/incidents/' . $id);
    }

    public function deleteIncident(Request $request, int $id): Response
    {
        $this->status->findIncident($id) ?? throw HttpException::notFound();
        $this->status->delete($id, $this->user($request)->id, $request->clientIp());
        $this->flash($request, 'success', 'Deleted.');

        return $this->redirect('/admin/status');
    }

    /**
     * @return array{0: string, 1: string|null} UTC start (now when empty) and end
     */
    private function window(mixed $start, mixed $end): array
    {
        $startsAt = is_string($start) && $start !== '' ? $this->status->toUtc($start) : $this->container->get(\Nebrix\Core\Clock::class)->nowString();
        $endsAt = is_string($end) && $end !== '' ? $this->status->toUtc($end) : null;
        if ($startsAt === null) {
            throw ValidationException::withMessages(['starts_at' => 'Enter a valid date and time.']);
        }
        if (is_string($end) && $end !== '' && ($endsAt === null || $endsAt <= $startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }

        return [$startsAt, $endsAt];
    }

    /**
     * @return list<int>
     */
    private function componentIds(mixed $input): array
    {
        return array_values(array_unique(array_filter(array_map('intval', is_array($input) ? $input : []), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return array<string, string>
     */
    private function integrations(): array
    {
        $list = [];
        foreach ($this->db->fetchAll('SELECT `id`, `name`, `type` FROM `nx_integrations` ORDER BY `name`') as $row) {
            $list[(string) $row['id']] = $row['name'] . ' (' . $row['type'] . ')';
        }

        return $list;
    }
}
