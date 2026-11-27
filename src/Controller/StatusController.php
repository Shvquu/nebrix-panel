<?php

declare(strict_types=1);

namespace Nebrix\Controller;

use Nebrix\Http\Controller;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Status\StatusService;

/**
 * Public status page (no sign-in): components, current incidents,
 * maintenance announcements and the last 14 days. Only published entries
 * are shown; nothing internal (integration names, staff) is exposed.
 */
final class StatusController extends Controller
{
    public function __construct(private readonly StatusService $status)
    {
    }

    public function show(Request $request): Response
    {
        $response = $this->render($request, 'status/index.twig', ['status' => $this->status->overview(), 'checked_at' => $this->container->get(\Nebrix\Core\Clock::class)->nowString()]);
        $response->setHeader('Cache-Control', 'no-cache');

        return $response;
    }

    /** Machine-readable status for monitoring tools. */
    public function json(Request $request): Response
    {
        $overview = $this->status->overview();
        $incident = static fn (array $i): array => [
            'id' => (int) $i['id'],
            'type' => (string) $i['type'],
            'title' => (string) $i['title'],
            'status' => (string) $i['status'],
            'impact' => (string) $i['impact'],
            'starts_at' => self::iso((string) $i['starts_at']),
            'ends_at' => $i['ends_at'] !== null ? self::iso((string) $i['ends_at']) : null,
            'components' => array_column($i['components'], 'name'),
            'updates' => array_map(static fn (array $u): array => ['status' => (string) $u['status'], 'message' => (string) $u['message'], 'created_at' => self::iso((string) $u['created_at'])], $i['updates']),
        ];
        $components = [];
        foreach ($overview['groups'] as $group => $items) {
            foreach ($items as $component) {
                $components[] = ['name' => (string) $component['name'], 'group' => $group !== '' ? $group : null, 'status' => (string) $component['effective_status']];
            }
        }
        $response = Response::json([
            'status' => $overview['state'],
            'components' => $components,
            'incidents' => array_map($incident, $overview['active']),
            'scheduled_maintenance' => array_map($incident, $overview['upcoming']),
        ]);
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setHeader('Access-Control-Allow-Origin', '*');

        return $response;
    }

    private static function iso(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}
