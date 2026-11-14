<?php

/**
 * OpenCatalogi Theme Featured Check Listener.
 *
 * Refuses to save a featured subject (a `theme` object) without a title, through
 * any API: the portal shows a featured subject by its title
 * (subjects-as-first-class-records, REQ-SUB-001).
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
 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-a-subject-can-be-featured-with-its-landing-data-req-sub-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Refuses a featured subject without a title.
 *
 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-a-subject-can-be-featured-with-its-landing-data-req-sub-001
 *
 * @template-implements IEventListener<Event>
 */
class ThemeFeaturedCheckListener implements IEventListener {

	/**
	 * The refusal, naming the missing property.
	 */
	public const MESSAGE = 'A featured subject needs a title';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Names the theme schema.
	 */
	public function __construct(
		private readonly IAppConfig $config,
	) {

	}//end __construct()

	/**
	 * Refuse the save before it is written when it features a subject without a title.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-a-subject-can-be-featured-with-its-landing-data-req-sub-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->refuseFeaturedWithoutTitle(event: $event, object: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->refuseFeaturedWithoutTitle(event: $event, object: $event->getNewObject());
		}

	}//end handle()

	/**
	 * Refuse the save, naming `title`, when it features a subject without one.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-save event.
	 * @param ObjectEntity                            $object The object as it would be written.
	 *
	 * @return void
	 */
	private function refuseFeaturedWithoutTitle(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		if ($this->featuresWithoutTitle(object: $object) === false) {
			return;
		}

		$event->setErrors(['message' => self::MESSAGE, 'property' => 'title']);
		$event->stopPropagation();

	}//end refuseFeaturedWithoutTitle()

	/**
	 * Whether the object is a subject saved as featured with no title.
	 *
	 * @param ObjectEntity $object The object as it would be written.
	 *
	 * @return bool True when the save must be refused.
	 */
	private function featuresWithoutTitle(ObjectEntity $object): bool {
		$schema = (string)$object->getSchema();
		if ($schema === '' || $schema !== $this->config->getValueString('opencatalogi', 'theme_schema', '')) {
			return false;
		}

		$data = $object->getObject();
		if (($data['featured'] ?? false) !== true) {
			return false;
		}

		$title = ($data['title'] ?? null);
		return is_string($title) === false || trim($title) === '';

	}//end featuresWithoutTitle()
}//end class
