<?php
/**
 * Unit tests for OpenCatalogiToolProvider.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Mcp;

use OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the MVP skeleton behaviour of OpenCatalogiToolProvider.
 */
class OpenCatalogiToolProviderTest extends TestCase
{

    /**
     * The provider under test.
     *
     * @var OpenCatalogiToolProvider
     */
    private OpenCatalogiToolProvider $provider;

    /**
     * Set up the provider with mocked dependencies.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $publicationService = $this->createMock(PublicationService::class);
        $userSession        = $this->createMock(IUserSession::class);
        $logger             = $this->createMock(LoggerInterface::class);

        $this->provider = new OpenCatalogiToolProvider(
            publicationService: $publicationService,
            userSession: $userSession,
            logger: $logger
        );

    }//end setUp()

    /**
     * getAppId() returns the opencatalogi app slug.
     *
     * @return void
     */
    public function testGetAppId(): void
    {
        $this->assertSame('opencatalogi', $this->provider->getAppId());

    }//end testGetAppId()

    /**
     * getTools() returns exactly 2 well-formed descriptors.
     *
     * @return void
     */
    public function testGetToolsReturnsTwoDescriptors(): void
    {
        $tools = $this->provider->getTools();

        $this->assertCount(2, $tools);

        $ids = [];
        foreach ($tools as $tool) {
            $this->assertIsArray($tool);
            $this->assertArrayHasKey('id', $tool);
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);

            $this->assertIsString($tool['id']);
            $this->assertStringStartsWith('opencatalogi.', $tool['id']);
            $this->assertNotEmpty($tool['description']);

            $this->assertIsArray($tool['inputSchema']);
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertArrayHasKey('properties', $tool['inputSchema']);
            $this->assertIsArray($tool['inputSchema']['properties']);

            $ids[] = $tool['id'];
        }

        $this->assertContains('opencatalogi.searchCatalog', $ids);
        $this->assertContains('opencatalogi.getPublication', $ids);

    }//end testGetToolsReturnsTwoDescriptors()

    /**
     * invokeTool() with an unknown tool id returns an error array without throwing.
     *
     * @return void
     */
    public function testInvokeUnknownToolReturnsErrorArray(): void
    {
        $result = $this->provider->invokeTool(toolId: 'opencatalogi.bogus', arguments: []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertIsArray($result['error']);
        $this->assertSame('unknown_tool', $result['error']['code']);
        $this->assertNotEmpty($result['error']['message']);

    }//end testInvokeUnknownToolReturnsErrorArray()

    /**
     * searchCatalog with a missing/empty query returns an invalid_arguments error.
     *
     * @return void
     */
    public function testSearchCatalogRejectsMissingQuery(): void
    {
        $result = $this->provider->invokeTool(toolId: 'opencatalogi.searchCatalog', arguments: []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_arguments', $result['error']['code']);

    }//end testSearchCatalogRejectsMissingQuery()

    /**
     * getPublication with a missing/empty id returns an invalid_arguments error.
     *
     * @return void
     */
    public function testGetPublicationRejectsMissingId(): void
    {
        $result = $this->provider->invokeTool(toolId: 'opencatalogi.getPublication', arguments: ['id' => '   ']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_arguments', $result['error']['code']);

    }//end testGetPublicationRejectsMissingId()
}//end class
