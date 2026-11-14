<?php

/**
 * A ThemesController over an in-memory register, for the subject tests.
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
 */

declare(strict_types=1);

namespace Unit\Controller;

require_once __DIR__ . '/../Service/Subject/FakeSubjectRegister.php';

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Controller\ThemesController;
use OCP\IAppConfig;
use Unit\Service\Subject\FakeSubjectRegister;
use Unit\Support\ServerAwareRequest;

/**
 * A ThemesController over an in-memory register.
 */
trait SubjectControllerFixture {

	/**
	 * The controller over this register, with these request parameters.
	 *
	 * @param FakeSubjectRegister  $register The register.
	 * @param array<string, mixed> $params   The request parameters.
	 *
	 * @return ThemesController
	 */
	private function themesController(FakeSubjectRegister $register, array $params = []): ThemesController {
		$request = $this->createMock(ServerAwareRequest::class);
		$request->server = [];
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);

		$registers = $this->createMock(OpenRegisterLookupInterface::class);
		$registers->method('installedObjectService')->willReturn($register);
		$registers->method('registerConfiguration')->willReturnCallback(
			static fn (string $registerKey, string $schemaKey): array => [
				'register' => '2',
				'schema' => $schemaKey === 'theme_schema' ? FakeSubjectRegister::THEME_SCHEMA : FakeSubjectRegister::PUBLICATION_SCHEMA,
			]
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);

		return new ThemesController('opencatalogi', $request, $config, $registers);
	}//end themesController()

	/**
	 * A theme object as OpenRegister answers it.
	 *
	 * @param string               $id     The uuid.
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return array<string, mixed>
	 */
	private function theme(string $id, array $fields): array {
		return array_merge(['id' => $id, '@self' => ['id' => $id, 'schema' => FakeSubjectRegister::THEME_SCHEMA]], $fields);
	}//end theme()
}//end trait
