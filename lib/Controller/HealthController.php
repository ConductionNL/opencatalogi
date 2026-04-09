<?php
/**
 * OpenCatalogi Health Controller.
 *
 * Exposes health check endpoint for container orchestration and monitoring.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Controller for health check endpoints.
 *
 * @psalm-suppress UnusedClass
 */
class HealthController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName    The application name.
     * @param IRequest        $request    The HTTP request.
     * @param IDBConnection   $db         Database connection.
     * @param IAppManager     $appManager App manager.
     * @param LoggerInterface $logger     Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private IDBConnection $db,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Health check endpoint.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Health status.
     */
    public function index(): JSONResponse
    {
        $checks = [];
        $status = 'ok';

        // Check database connectivity.
        $checks['database'] = $this->checkDatabase();
        if ($checks['database'] !== 'ok') {
            $status = 'error';
        }

        // Check filesystem.
        $checks['filesystem'] = $this->checkFilesystem();
        if ($checks['filesystem'] !== 'ok' && $status !== 'error') {
            $status = 'degraded';
        }

        // Check search backend.
        $checks['search_backend'] = $this->checkSearchBackend();

        // Only database failure is critical (503). Degraded is still 200.
        $httpStatus = Http::STATUS_OK;
        if ($status === 'error') {
            $httpStatus = Http::STATUS_SERVICE_UNAVAILABLE;
        }

        return new JSONResponse(
            data: [
                'status'  => $status,
                'version' => $this->getAppVersion(),
                'checks'  => $checks,
            ],
            statusCode: $httpStatus
        );

    }//end index()

    /**
     * Check database connectivity.
     *
     * @return string 'ok' or error message.
     */
    private function checkDatabase(): string
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('1'));
            $result = $qb->executeQuery();
            $result->closeCursor();

            return 'ok';
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[HealthController] Database check failed',
                context: ['error' => $e->getMessage()]
            );
            return 'failed: '.$e->getMessage();
        }

    }//end checkDatabase()

    /**
     * Check filesystem access.
     *
     * @return string 'ok' or error message.
     */
    private function checkFilesystem(): string
    {
        try {
            $tmpFile = sys_get_temp_dir().'/opencatalogi_health_'.getmypid();
            $written = file_put_contents(
                filename: $tmpFile,
                data: 'health'
            );
            if ($written === false) {
                return 'failed: cannot write to temp directory';
            }

            unlink($tmpFile);

            return 'ok';
        } catch (\Exception $e) {
            return 'failed: '.$e->getMessage();
        }

    }//end checkFilesystem()

    /**
     * Check search backend availability.
     *
     * @return string Backend type and status.
     */
    private function checkSearchBackend(): string
    {
        try {
            // Check if ElasticSearch is configured.
            $container = \OCP\Server::get(\Psr\Container\ContainerInterface::class);
            $esService = $container->get(\OCA\OpenCatalogi\Service\ElasticSearchService::class);
            if ($esService !== null && method_exists($esService, 'isAvailable') === true) {
                return $esService->isAvailable() === true ? 'elasticsearch: ok' : 'elasticsearch: unreachable';
            }

            return 'database';
        } catch (\Exception $e) {
            // ElasticSearch not configured — using database backend.
            return 'database';
        }

    }//end checkSearchBackend()

    /**
     * Get the app version.
     *
     * @return string The app version.
     */
    private function getAppVersion(): string
    {
        try {
            return $this->appManager->getAppVersion(appId: 'opencatalogi');
        } catch (\Exception $e) {
            return 'unknown';
        }

    }//end getAppVersion()
}//end class
