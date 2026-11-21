<?php
/**
 * Publisher identity
 *
 * The organisation a publication names as its `organization` is the publisher.
 * Its RSIN, name and TOOI identifier travel with every stored document, so this
 * class reads them from OpenRegister's shared `nc-organisation` projection. An
 * RSIN is answered only when it is nine digits that pass the 11-proef; anything
 * else is answered as missing, with the reason, so nothing wrong is stamped.
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
 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the publisher's RSIN, name and TOOI identifier for a publication.
 *
 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
 */
class PublisherIdentity {
	use ReadsObjectProperties;

	/**
	 * The RSIN is valid and may be stamped.
	 *
	 * @var string
	 */
	public const STATUS_VALID = 'valid';

	/**
	 * There is no RSIN that may be stamped; `reason` says why.
	 *
	 * @var string
	 */
	public const STATUS_MISSING = 'missing';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookup $openRegister OpenRegister's ObjectService and the organisation register and schema.
	 * @param LoggerInterface    $logger       The log.
	 */
	public function __construct(
		private readonly OpenRegisterLookup $openRegister,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The publisher's identity for a publication.
	 *
	 * `rsin` is set only when the organisation's RSIN passes the 11-proef; then
	 * `rsinStatus` is `valid` and `reason` is null. Otherwise `rsin` is null,
	 * `rsinStatus` is `missing` and `reason` names why. `name` and `tooi` are
	 * answered whenever the organisation could be read.
	 *
	 * @param array<string, mixed> $publication The publication's properties.
	 *
	 * @return array{rsin: ?string, name: ?string, tooi: ?string, rsinStatus: string, reason: ?string} The identity.
	 *
	 * @SuppressWarnings(PHPMD.ShortMethodName) REQ-PRO-002 names this method `PublisherIdentity::of()`.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
	 */
	public function of(array $publication): array {
		$organisationId = self::referenceOf(value: ($publication['organization'] ?? null));
		if ($organisationId === '') {
			return self::missing(reason: 'The publication names no organisation.');
		}

		$organisation = $this->organisation(id: $organisationId);
		if ($organisation === null) {
			return self::missing(reason: 'The organisation of the publication cannot be read.');
		}

		$name = self::text(value: ($organisation['name'] ?? null));
		$tooi = self::text(value: ($organisation['tooi'] ?? null));
		$rsin = self::text(value: ($organisation['rsin'] ?? null));

		if ($rsin === null) {
			return self::missing(reason: 'The organisation has no RSIN.', name: $name, tooi: $tooi);
		}

		if (self::passesElevenTest(rsin: $rsin) === false) {
			return self::missing(reason: 'The RSIN of the organisation fails the 11-proef.', name: $name, tooi: $tooi);
		}

		return [
			'rsin'       => $rsin,
			'name'       => $name,
			'tooi'       => $tooi,
			'rsinStatus' => self::STATUS_VALID,
			'reason'     => null,
		];

	}//end of()

	/**
	 * Whether a string is a nine-digit number that passes the 11-proef.
	 *
	 * The first eight digits are weighted 9 down to 2 and the last one -1; the
	 * sum must be a multiple of eleven. All zeros sums to zero and is refused.
	 *
	 * @param string $rsin The candidate RSIN.
	 *
	 * @return bool True when it passes.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
	 */
	public static function passesElevenTest(string $rsin): bool {
		if (preg_match('/^\d{9}$/', $rsin) !== 1 || $rsin === '000000000') {
			return false;
		}

		$sum = 0;
		for ($position = 0; $position < 8; $position++) {
			$sum += ((int) $rsin[$position] * (9 - $position));
		}

		$sum -= (int) $rsin[8];

		return ($sum % 11) === 0;

	}//end passesElevenTest()

	/**
	 * The organisation's properties, read past RBAC, or null when it cannot be read.
	 *
	 * @param string $id The organisation's id.
	 *
	 * @return array<string, mixed>|null The properties.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
	 */
	private function organisation(string $id): ?array {
		try {
			$pair    = $this->openRegister->registerConfiguration(
				registerKey: 'organization_register',
				schemaKey: 'organization_schema'
			);
			$objects = $this->openRegister->installedObjectService();
			$entity  = $objects->find(
				id: $id,
				options: new ObjectFindOptions(register: $pair['register'], schema: $pair['schema']),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[PublisherIdentity] The organisation {id} could not be read: {message}',
				['id' => $id, 'message' => $e->getMessage()]
			);
			return null;
		}

		$properties = $this->asArray(object: $entity);
		if ($properties === []) {
			return null;
		}

		return $properties;

	}//end organisation()

	/**
	 * The id a reference holds: a plain id, or the `id`/`uuid` of an expanded object.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The id, or an empty string.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
	 */
	private static function referenceOf(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false && is_int($value) === false) {
			return '';
		}

		return trim((string) $value);

	}//end referenceOf()

	/**
	 * A trimmed, non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
	 */
	private static function text(mixed $value): ?string {
		if (is_string($value) === false && is_int($value) === false) {
			return null;
		}

		$text = trim((string) $value);
		if ($text === '') {
			return null;
		}

		return $text;

	}//end text()

	/**
	 * An identity without a stampable RSIN.
	 *
	 * @param string      $reason Why there is none.
	 * @param string|null $name   The organisation's name, when read.
	 * @param string|null $tooi   The organisation's TOOI identifier, when read.
	 *
	 * @return array{rsin: null, name: ?string, tooi: ?string, rsinStatus: string, reason: string} The identity.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#requirement-the-publishers-rsin-travels-with-every-stored-document-req-pro-002
	 */
	private static function missing(string $reason, ?string $name=null, ?string $tooi=null): array {
		return [
			'rsin'       => null,
			'name'       => $name,
			'tooi'       => $tooi,
			'rsinStatus' => self::STATUS_MISSING,
			'reason'     => $reason,
		];

	}//end missing()
}//end class
