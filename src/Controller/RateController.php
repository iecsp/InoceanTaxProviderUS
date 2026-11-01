<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Controller;
use InoceanSalesTaxesUsa\Service\RateSyncService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
final class RateController
{
    public function __construct(private readonly RateSyncService $sync) {}

    #[Route(path: '/api/_action/inocean-usa-tax/status', name: 'api.action.inocean_usa_tax.status', methods: ['GET'], defaults: ['_acl' => ['system_config:read']])]
    public function status(): JsonResponse { return new JsonResponse(['sources' => $this->sync->status(), 'supportedStates' => ['CA', 'WA']]); }

    #[Route(path: '/api/_action/inocean-usa-tax/sync', name: 'api.action.inocean_usa_tax.sync', methods: ['POST'], defaults: ['_acl' => ['system_config:update']])]
    public function sync(\Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        try { return new JsonResponse(['jobId' => $this->sync->queue((string) $request->query->get('state', 'CA'))], 202); }
        catch (\Throwable) { return new JsonResponse(['errors' => [['detail' => 'Cannot queue synchronization. Check migration, worker and server logs.']]], 503); }
    }
}
