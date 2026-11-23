<?php

/**
 * OpenCatalogi Licence Check.
 *
 * A catalogue's `dcatLicense` and a publication's `license` are emitted in the
 * DCAT feed only as a member of the EU licences authority list. A save that
 * writes a new value outside that list is refused before it is written, so the
 * officer learns it at the form and not later from the feed validator. A value
 * already stored and left unchanged passes: the feed omits and reports it, and
 * an unrelated edit of a legacy record is not blocked.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
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
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCP\IL10N;
use OCP\IAppConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenCatalogi\Service\DcatMappingService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * Refuses a new licence outside the EU licences authority list on a catalogue or publication.
 *
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
 *
 * @template-implements IEventListener<Event>
 */
class LicenceCheck implements IEventListener {

	/**
	 * The licence property per app config key of the schema that carries it.
	 */
	public const FIELDS = [
		'catalog_schema' => 'dcatLicense',
		'publication_schema' => 'license',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Names the catalogue and publication schemas.
	 * @param DcatMappingService $mapping Binds a licence to its authority IRI, as the feed does.
	 * @param IL10N $l10n Localisation.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly DcatMappingService $mapping,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Refuse a save that writes a new licence the list does not hold.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === true) {
			$written = $event->getNewObject();
			$stored  = ($event->getOldObject()?->getObject() ?? []);
			$this->check(event: $event, schema: (string)$written->getSchema(), data: ($written->getObject() ?? []), stored: $stored);
			return;
		}

		if ($event instanceof ObjectCreatingEvent === true) {
			$written = $event->getObject();
			$this->check(event: $event, schema: (string)$written->getSchema(), data: ($written->getObject() ?? []), stored: []);
		}

	}//end handle()

	/**
	 * Refuse the save, naming the property, when its licence may not be saved.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 * @param string $schema The schema id of the written object.
	 * @param array<string, mixed> $data The object as it would be written.
	 * @param array<string, mixed> $stored The object as stored, empty on create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $schema, array $data, array $stored): void {
		$field = $this->fieldOf(schema: $schema);
		if ($field === null || $this->isAcceptable(value: ($data[$field] ?? null), stored: ($stored[$field] ?? null)) === true) {
			return;
		}

		$event->setErrors(['message' => $this->l10n->t('Choose a licence from the EU licences authority list.'), 'property' => $field]);
		$event->stopPropagation();

	}//end check()

	/**
	 * Whether a written licence may be saved: none, unchanged, or a member of the list.
	 *
	 * @param mixed $value The licence the save writes.
	 * @param mixed $stored The licence stored before the save.
	 *
	 * @return bool True when the save may go on.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	private function isAcceptable(mixed $value, mixed $stored): bool {
		if ($value === null || $value === '' || $value === $stored) {
			return true;
		}

		return $this->mapping->licenceNode(value: $value) !== null;

	}//end isAcceptable()

	/**
	 * The licence property of a schema, or null when it is neither the catalogue nor the publication schema.
	 *
	 * @param string $schema The schema id of the written object.
	 *
	 * @return string|null The property name.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	private function fieldOf(string $schema): ?string {
		if ($schema === '') {
			return null;
		}

		foreach (self::FIELDS as $key => $field) {
			if ($schema === trim($this->config->getValueString('opencatalogi', $key, ''))) {
				return $field;
			}
		}

		return null;

	}//end fieldOf()
}//end class
