<?php

/**
 * The order of a publication's documents on its public attachment list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-an-officer-orders-a-publication-s-documents-and-the-public-list-keeps-that-order-req-pdp-001
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\Publication\AttachmentOrder;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\AttachmentOrder
 */
class AttachmentOrderTest extends TestCase {

	/**
	 * A file node with an id, a name and an upload time.
	 *
	 * @param int    $id       The file id.
	 * @param string $name     The name.
	 * @param int    $uploaded The upload time.
	 *
	 * @return File
	 */
	private function file(int $id, string $name, int $uploaded): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getUploadTime')->willReturn($uploaded);
		$file->method('getMTime')->willReturn($uploaded + 5);

		return $file;
	}//end file()

	/**
	 * The names of the files, in order.
	 *
	 * @param array<int, File> $files The files.
	 *
	 * @return list<string>
	 */
	private function names(array $files): array {
		return array_map(static fn (File $file): string => $file->getName(), $files);
	}//end names()

	/**
	 * The scenario of REQ-PDP-001: "Besluit", "Bijlage 1", "Bijlage 2".
	 *
	 * @return void
	 */
	public function testTheListFollowsTheStoredOrder(): void {
		$files = [
			$this->file(id: 11, name: 'Bijlage 2', uploaded: 100),
			$this->file(id: 12, name: 'Besluit', uploaded: 200),
			$this->file(id: 13, name: 'Bijlage 1', uploaded: 300),
		];

		$sorted = AttachmentOrder::sort(files: $files, order: ['12', '13', '11']);

		$this->assertSame(['Besluit', 'Bijlage 1', 'Bijlage 2'], $this->names($sorted));
	}//end testTheListFollowsTheStoredOrder()

	/**
	 * A file uploaded after the order was saved goes last, and the rest by upload time.
	 *
	 * @return void
	 */
	public function testAFileNotInTheOrderComesLast(): void {
		$files = [
			$this->file(id: 3, name: 'Later', uploaded: 900),
			$this->file(id: 1, name: 'First', uploaded: 100),
			$this->file(id: 4, name: 'Earlier unordered', uploaded: 50),
			$this->file(id: 2, name: 'Second', uploaded: 200),
		];

		$sorted = AttachmentOrder::sort(files: $files, order: ['2', '1']);

		$this->assertSame(['Second', 'First', 'Earlier unordered', 'Later'], $this->names($sorted));
	}//end testAFileNotInTheOrderComesLast()

	/**
	 * An id in the order that is no longer attached is skipped.
	 *
	 * @return void
	 */
	public function testADetachedIdIsSkipped(): void {
		$files = [
			$this->file(id: 1, name: 'One', uploaded: 100),
			$this->file(id: 2, name: 'Two', uploaded: 200),
		];

		$sorted = AttachmentOrder::sort(files: $files, order: ['99', '2', '1']);

		$this->assertSame(['Two', 'One'], $this->names($sorted));
	}//end testADetachedIdIsSkipped()

	/**
	 * Without an upload time the modification time decides.
	 *
	 * @return void
	 */
	public function testWithoutAnUploadTimeTheModificationTimeDecides(): void {
		$old = $this->createMock(File::class);
		$old->method('getId')->willReturn(1);
		$old->method('getName')->willReturn('Old');
		$old->method('getUploadTime')->willReturn(0);
		$old->method('getMTime')->willReturn(10);
		$new = $this->file(id: 2, name: 'New', uploaded: 20);

		$sorted = AttachmentOrder::sort(files: [$new, $old], order: []);

		$this->assertSame(['Old', 'New'], $this->names($sorted));
	}//end testWithoutAnUploadTimeTheModificationTimeDecides()

	/**
	 * The stored order is read as strings, without duplicates or other values.
	 *
	 * @return void
	 */
	public function testTheStoredOrderIsReadAsStrings(): void {
		$this->assertSame(['12', '13'], AttachmentOrder::storedOrder(publication: ['attachmentOrder' => [12, '13', '12', null, ['x']]]));
		$this->assertSame([], AttachmentOrder::storedOrder(publication: ['attachmentOrder' => 'not-a-list']));
		$this->assertSame([], AttachmentOrder::storedOrder(publication: ['title' => 'No order']));
		$this->assertSame([], AttachmentOrder::storedOrder(publication: null));

		$object = new class () implements \JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['attachmentOrder' => ['7']];
			}
		};
		$this->assertSame(['7'], AttachmentOrder::storedOrder(publication: $object));
	}//end testTheStoredOrderIsReadAsStrings()

	/**
	 * Positions count from 1 and continue across pages.
	 *
	 * @return void
	 */
	public function testPositionsCountFromOneAcrossPages(): void {
		$first = AttachmentOrder::withPositions(page: ['results' => [['id' => 1], ['id' => 2]], 'offset' => 0]);
		$second = AttachmentOrder::withPositions(page: ['results' => [['id' => 3]], 'offset' => 2]);

		$this->assertSame([1, 2], array_column($first['results'], 'position'));
		$this->assertSame([3], array_column($second['results'], 'position'));
		$this->assertSame(['error' => 'x'], AttachmentOrder::withPositions(page: ['error' => 'x']));
	}//end testPositionsCountFromOneAcrossPages()
}//end class
