<?php

/**
 * Publication review policy
 *
 * Whether a publication has to pass review before it can be published. One
 * admin setting for every catalogue: `publication_review_required`, stored as
 * the text `true` or `false`, true when unset (publication-lifecycle-on-or
 * REQ-PLC-001). Fail closed: anything but the text `false` keeps review on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCP\IAppConfig;

/**
 * Reads and writes the one review setting.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
 */
class PublicationReviewPolicy {

	/**
	 * The app config key.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'publication_review_required';

	/**
	 * The app id the setting is stored under.
	 *
	 * @var string
	 */
	private const APP_ID = 'opencatalogi';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the setting.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether review is required; true unless the setting says `false`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
	 */
	public function isReviewRequired(): bool {
		return $this->appConfig->getValueString(self::APP_ID, self::CONFIG_KEY, 'true') !== 'false';
	}//end isReviewRequired()
}//end class
