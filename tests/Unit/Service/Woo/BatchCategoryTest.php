<?php

/**
 * A batch keeps the category the registry serves, else it is filed as a Woo request.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Unit\Support\ServedCategories;

/**
 * Tests for BatchPublicationWriter::category().
 */
class BatchCategoryTest extends TestCase {

	/**
	 * A writer whose container answers the registry, or has none.
	 *
	 * @param InformationCategoryRegistryInterface|null $registry The registry, or null for none.
	 *
	 * @return BatchPublicationWriter
	 */
	private function writer(?InformationCategoryRegistryInterface $registry): BatchPublicationWriter {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registry): object {
				if ($id === InformationCategoryRegistryInterface::class && $registry !== null) {
					return $registry;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		return new BatchPublicationWriter(
			$this->createMock(IRootFolder::class),
			$container,
			$this->createMock(PublicationLinker::class),
			$this->createMock(IL10N::class)
		);
	}//end writer()

	/**
	 * A value-list member the register holds is kept.
	 *
	 * @return void
	 */
	public function testAServedCategoryIsKept(): void {
		$this->assertSame('infocat010', $this->writer(registry: new ServedCategories())->category(batch: ['wooCategory' => 'infocat010']));
	}

	/**
	 * An operator-added category is kept, which a constant list could not know.
	 *
	 * @return void
	 */
	public function testAnOperatorAddedCategoryIsKept(): void {
		$writer = $this->writer(registry: new ServedCategories(added: ['aanbestedingen']));

		$this->assertSame('aanbestedingen', $writer->category(batch: ['wooCategory' => 'aanbestedingen']));
	}

	/**
	 * An unknown or missing category is filed as a Woo request.
	 *
	 * @return void
	 */
	public function testAnUnknownCategoryIsFiledAsAWooRequest(): void {
		$writer = $this->writer(registry: new ServedCategories());

		$this->assertSame('infocat014', $writer->category(batch: ['wooCategory' => 'infocat099']));
		$this->assertSame('infocat014', $writer->category(batch: []));
	}

	/**
	 * Without a registry, or with a register that cannot be read, every batch is filed as a Woo request.
	 *
	 * @return void
	 */
	public function testWithoutTheRegistryTheBatchIsFiledAsAWooRequest(): void {
		$this->assertSame('infocat014', $this->writer(registry: null)->category(batch: ['wooCategory' => 'infocat010']));
		$this->assertSame('infocat014', $this->writer(registry: new ServedCategories(unreadable: true))->category(batch: ['wooCategory' => 'infocat010']));
	}
}//end class
