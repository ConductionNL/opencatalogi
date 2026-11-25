<?php

/**
 * OpenCatalogi organisation activation
 *
 * An administrator activates, out of the TOOI organisation registers, the
 * organisations this installation publishes for. The choice is a
 * `valueListChoice` with `active`; activating links the OpenRegister
 * organisation that carries the TOOI identifier, or creates it.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\ValueList
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
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\ValueList;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\OrganisationActivationInterface;
use OCA\OpenCatalogi\Contract\VocabularyRegisterInterface;
use OCA\OpenCatalogi\Service\Vocabulary\ValueListRefresh;
use RuntimeException;

/**
 * Searches the TOOI organisation registers, and activates or deactivates an organisation.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */
class OrganisationActivation implements OrganisationActivationInterface {

	/**
	 * The value list the choices about organisations are stored under.
	 *
	 * @var string
	 */
	public const SCHEME = 'organisaties';

	/**
	 * The most search results answered.
	 *
	 * @var int
	 */
	public const SEARCH_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param VocabularyRegisterInterface $vocabulary    OpenRegister's vocabulary register, which holds the TOOI registers.
	 * @param ValueListRefresh            $refresh       Names the TOOI organisation registers among the refreshed lists.
	 * @param ValueListChoiceStore        $choices       The organisation's choices.
	 * @param OpenRegisterOrganisations   $organisations OpenRegister's organisations, by TOOI identifier.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly VocabularyRegisterInterface $vocabulary,
		private readonly ValueListRefresh $refresh,
		private readonly ValueListChoiceStore $choices,
		private readonly OpenRegisterOrganisations $organisations,
	) {
	}//end __construct()

	/**
	 * The organisations of the TOOI registers whose name or code holds the query, activated ones first.
	 *
	 * A deprecated organisation is answered only while it is activated, so an
	 * administrator can still see and deactivate it.
	 *
	 * @param string $query What the name or code holds; empty answers the activated organisations.
	 *
	 * @return array{results: list<array{uri: string, label: string, notation: string, active: bool, deprecated: bool}>, total: int}
	 *
	 * @throws RuntimeException When the vocabulary or the choices cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function search(string $query): array {
		$needle = mb_strtolower(trim($query));
		$active = $this->activeEntries();
		$found = [];
		foreach ($this->concepts() as $uri => $concept) {
			$isActive = in_array($uri, $active, true);
			if ($concept['deprecated'] === true && $isActive === false) {
				continue;
			}

			$named = (str_contains(mb_strtolower($concept['label']), $needle) === true
				|| str_contains(mb_strtolower($concept['notation']), $needle) === true);
			$matches = ($needle === '' && $isActive === true) || ($needle !== '' && $named === true);
			if ($matches === true) {
				$found[] = array_merge(['uri' => $uri], $concept, ['active' => $isActive]);
			}
		}

		usort(
			$found,
			static fn (array $a, array $b): int => [$b['active'], mb_strtolower($a['label'])] <=> [$a['active'], mb_strtolower($b['label'])]
		);

		return ['results' => array_slice($found, 0, self::SEARCH_LIMIT), 'total' => count($found)];
	}//end search()

	/**
	 * Activate an organisation: link the OpenRegister organisation with its TOOI identifier, or create it.
	 *
	 * @param string $uri The TOOI identifier of an organisation in one of the registers.
	 *
	 * @return array{uri: string, label: string, organisation: string, created: bool} What was activated and which organisation it is.
	 *
	 * @throws InvalidArgumentException When the uri is not an organisation of the registers, or it is deprecated.
	 * @throws RuntimeException         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function activate(string $uri): array {
		$concept = ($this->concepts()[$uri] ?? null);
		if ($concept === null) {
			throw new InvalidArgumentException('The TOOI organisation registers hold no organisation ' . $uri . '.');
		}

		if ($concept['deprecated'] === true) {
			throw new InvalidArgumentException('The organisation ' . $concept['label'] . ' no longer exists in the TOOI register.');
		}

		$created = false;
		$organisation = $this->organisations->findByTooi(tooi: $uri);
		if ($organisation === null) {
			$organisation = $this->organisations->create(name: $concept['label'], tooi: $uri);
			$created = true;
		}

		$this->choices->save(choice: ['scheme' => self::SCHEME, 'entry' => $uri, 'active' => true]);

		return ['uri' => $uri, 'label' => $concept['label'], 'organisation' => $organisation['uuid'], 'created' => $created];
	}//end activate()

	/**
	 * Deactivate an organisation; its OpenRegister organisation and publications stay as they are.
	 *
	 * @param string $uri The TOOI identifier.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the choices cannot be written.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function deactivate(string $uri): void {
		$this->choices->save(choice: ['scheme' => self::SCHEME, 'entry' => $uri, 'active' => false]);
	}//end deactivate()

	/**
	 * The TOOI identifiers of the activated organisations.
	 *
	 * @return list<string> The identifiers; empty while nothing is activated.
	 *
	 * @throws RuntimeException When the choices cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function activeEntries(): array {
		$active = [];
		foreach ($this->choices->forScheme(scheme: self::SCHEME) as $uri => $choice) {
			if ($choice['active'] === true) {
				$active[] = (string)$uri;
			}
		}

		return $active;
	}//end activeEntries()

	/**
	 * The concepts of every TOOI organisation register, by uri.
	 *
	 * @return array<string, array{label: string, notation: string, deprecated: bool}>
	 *
	 * @throws RuntimeException When the vocabulary cannot be read.
	 */
	private function concepts(): array {
		$concepts = [];
		foreach ($this->refresh->organisationSchemes() as $scheme) {
			$concepts = array_merge($concepts, $this->vocabulary->concepts(schemeUri: $scheme));
		}

		return $concepts;
	}//end concepts()
}//end class
