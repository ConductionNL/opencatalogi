<?php

/**
 * Decides whether a publication is complete and public.
 *
 * A source system that hands a record to OpenCatalogi is told when the
 * record, with every document on it, is public (publication-tells-its-source).
 * The telling is an OpenRegister webhook on the move of `completeness` from
 * `incomplete` to `complete`, so this service only answers the question; it
 * never sends anything.
 *
 * It fails closed. `complete` needs the publication to be public, every file
 * on it to have a public share, and at least as many files as the source said
 * it would send. A lookup that fails throws, so a caller keeps the stored value
 * instead of claiming a completeness nobody checked.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Evaluates `completeness` and works out the properties to store for it.
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */
class CompletenessService {

	/**
	 * The publication is public and so is every document on it.
	 *
	 * @var string
	 */
	public const COMPLETE = 'complete';

	/**
	 * Anything short of complete, and the state of a publication nobody evaluated.
	 *
	 * @var string
	 */
	public const INCOMPLETE = 'incomplete';

	/**
	 * OpenRegister's file mapper, looked up by name: OpenCatalogi boots without OpenRegister.
	 *
	 * @var string
	 */
	public const FILE_MAPPER = 'OCA\OpenRegister\Files\Db\FileMapper';

	/**
	 * Constructor.
	 *
	 * @param PublicationStateServiceInterface $states    Whether the publication is public.
	 * @param ContainerInterface               $container Resolves OpenRegister's file mapper.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	public function __construct(
		private readonly PublicationStateServiceInterface $states,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether the publication is complete and public.
	 *
	 * @param array<string, mixed>   $publication The publication's properties.
	 * @param ObjectEntity           $object      The stored object, whose files are read.
	 * @param DateTimeInterface|null $now         The moment, now when omitted.
	 *
	 * @return string COMPLETE or INCOMPLETE.
	 *
	 * @throws RuntimeException When the files cannot be read.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	public function evaluate(array $publication, ObjectEntity $object, ?DateTimeInterface $now = null): string {
		if ($this->states->stateOf(publication: $publication, now: $now) !== PublicationStateServiceInterface::STATE_PUBLIC) {
			return self::INCOMPLETE;
		}

		$files = $this->filesOf(object: $object);
		foreach ($files as $file) {
			// A file without a share is not public, and a withdrawn file has had its share removed.
			if (is_array($file) === false || trim((string)($file['share_token'] ?? '')) === '') {
				return self::INCOMPLETE;
			}
		}

		$expected = $publication['expectedDocuments'] ?? null;
		if (is_numeric($expected) === true && count($files) < (int)$expected) {
			return self::INCOMPLETE;
		}

		return self::COMPLETE;
	}//end evaluate()

	/**
	 * The completeness properties to store for an evaluated value.
	 *
	 * `completeAt` is set when the publication becomes complete and kept while
	 * it stays complete; an incomplete publication keeps the moment it was last
	 * complete, if any.
	 *
	 * @param string                 $value    The evaluated value.
	 * @param array<string, mixed>   $previous The stored properties before this write, or [] for none.
	 * @param DateTimeInterface|null $now      The moment, now when omitted.
	 *
	 * @return array<string, string> `completeness`, and `completeAt` when there is one.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	public function propertiesFor(string $value, array $previous, ?DateTimeInterface $now = null): array {
		$properties = ['completeness' => $value];
		$since      = $previous['completeAt'] ?? null;
		if ($value === self::COMPLETE && (($previous['completeness'] ?? null) !== self::COMPLETE || is_string($since) === false || $since === '')) {
			$since = $this->moment(now: $now)->format(DateTimeInterface::ATOM);
		}

		if (is_string($since) === true && $since !== '') {
			$properties['completeAt'] = $since;
		}

		return $properties;
	}//end propertiesFor()

	/**
	 * The stored completeness properties, for a write that could not be evaluated.
	 *
	 * @param array<string, mixed> $previous The stored properties, or [] for none.
	 *
	 * @return array<string, string> `completeness` as stored (incomplete when never set), and `completeAt` when stored.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	public function storedProperties(array $previous): array {
		$value = self::INCOMPLETE;
		if (($previous['completeness'] ?? null) === self::COMPLETE) {
			$value = self::COMPLETE;
		}

		$properties = ['completeness' => $value];
		if (is_string($previous['completeAt'] ?? null) === true && $previous['completeAt'] !== '') {
			$properties['completeAt'] = $previous['completeAt'];
		}

		return $properties;
	}//end storedProperties()

	/**
	 * The files OpenRegister holds for the object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, mixed> The file rows.
	 *
	 * @throws RuntimeException When OpenRegister's file mapper is missing or fails.
	 */
	private function filesOf(ObjectEntity $object): array {
		if ($this->container->has(self::FILE_MAPPER) === false) {
			throw new RuntimeException('OpenRegister cannot list the files of a publication on this server.');
		}

		try {
			$files = $this->container->get(self::FILE_MAPPER)->getFilesForObject($object);
		} catch (\Throwable $e) {
			throw new RuntimeException('The files of the publication could not be read: ' . $e->getMessage(), 0, $e);
		}

		return array_values((array)$files);
	}//end filesOf()

	/**
	 * The moment, in UTC.
	 *
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function moment(?DateTimeInterface $now): DateTimeImmutable {
		if ($now !== null) {
			return DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));
		}

		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}//end moment()
}//end class
