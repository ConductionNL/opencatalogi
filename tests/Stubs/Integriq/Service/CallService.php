<?php

/**
 * Integriq CallService test stub.
 *
 * Mirrors the signature of OCA\Integriq\Service\CallService::call() on integriq
 * `development` (lib/Service/CallService.php:3033, read 29 Sep 2026): the source
 * is an ObjectEntity, not a name, and the method returns the call log as an
 * ObjectEntity. Loaded only when the real class is absent; in CI the real class
 * is installed and the tests run against it.
 *
 * @category Tests
 * @package  OCA\Integriq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The outbound HTTP engine of integriq.
 */
class CallService {

	/**
	 * Call a source.
	 *
	 * @param ObjectEntity $source The source object.
	 * @param string $endpoint The endpoint.
	 * @param string $method The HTTP method.
	 * @param array $config Guzzle configuration.
	 * @param bool $asynchronous Whether to call asynchronously.
	 * @param bool $createCertificates Whether to create certificates.
	 * @param bool $overruleAuth Whether to overrule authentication.
	 * @param bool $read Whether this is a read.
	 * @param bool $runningSupportRequest Whether a support request runs.
	 * @param mixed $sink A sink.
	 * @param mixed $trace The execution trace context.
	 * @param bool $persistLog Whether to persist the call log.
	 *
	 * @return ObjectEntity The call log.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function call(
		ObjectEntity $source,
		string $endpoint = '',
		string $method = 'GET',
		array $config = [],
		bool $asynchronous = false,
		bool $createCertificates = true,
		bool $overruleAuth = false,
		bool $read = false,
		bool $runningSupportRequest = false,
		mixed $sink = null,
		mixed $trace = null,
		bool $persistLog = true,
	): ObjectEntity {
		throw new \LogicException('Stub: mock CallService::call() in the test.');
	}//end call()
}//end class
