<?php

/**
 * OpenCatalogi Themes Controller.
 *
 * Controller for handling theme-related operations in the OpenCatalogi app.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/content-management/spec.md
 * @spec openspec/specs/content-management/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Subject\SubjectPublications;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Controller for handling theme-related operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *   Catching DoesNotExistException in show() takes the coupling count from 12 to 13.
 *   The six sibling controllers that resolve OpenRegister objects — including
 *   ListingsController and PublicationsController, whose scoped-find() shape show()
 *   now follows — already carry this suppression for the same reason. Dropping the
 *   catch to keep a design metric one point lower would leave a public JSON route
 *   answering an unknown identifier with an HTML 500.
 */
class ThemesController extends Controller {
	use ResolvesRegisterConfiguration;
	use ListsConfiguredObjects;

	/**
	 * ThemesController constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param IAppConfig $config App configuration interface.
	 * @param OpenRegisterLookupInterface $registers Resolves the configured register pair and OpenRegister's ObjectService.
	 * @param CorsSettings $cors Allowed CORS methods, headers and max age.
	 * @param SubjectPublications|null $subjects Lists and counts the public publications under a subject.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly OpenRegisterLookupInterface $registers,
		private readonly CorsSettings $cors = new CorsSettings(),
		?SubjectPublications $subjects = null,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->subjects = ($subjects ?? new SubjectPublications(registers: $registers));

	}//end __construct()

	/**
	 * Lists and counts the public publications under a subject.
	 *
	 * @var SubjectPublications
	 */
	private readonly SubjectPublications $subjects;

	/**
	 * The keys a featured subject answers with (REQ-SUB-002).
	 */
	private const FEATURED_KEYS = ['id', 'slug', 'title', 'summary', 'description', 'image', 'url', 'isExternal', 'featuredOrder'];

	/**
	 * The most featured subjects one answer lists.
	 */
	private const FEATURED_LIMIT = 100;

	/**
	 * Attempts to retrieve the OpenRegister ObjectService from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The ObjectService.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		return $this->registers->installedObjectService();
	}//end getObjectService()

	/**
	 * Get the schema and register configuration for themes.
	 *
	 * Resolved through OpenRegister's RegisterResolverService (no empty-string
	 * fallback); an unconfigured `theme_register`/`theme_schema` raises
	 * MissingConfigException which the caller converts to a 503.
	 *
	 * @return array<string, string> Array containing schema and register configuration.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 * @throws \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException When a context key is unconfigured.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
	 */
	private function getThemeConfiguration(): array {
		return $this->resolveRegisterConfiguration(registerKey: 'theme_register', schemaKey: 'theme_schema');
	}//end getThemeConfiguration()

	/**
	 * Implements a preflighted CORS response for OPTIONS requests.
	 *
	 * @return Response The CORS response.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		return $this->withListPreflightHeaders(response: new Response());
	}//end preflightedCors()

	/**
	 * Get all themes with pagination support.
	 *
	 * @return JSONResponse The JSON response containing the list of themes.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/content-management/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		// Get theme configuration from settings (resolved via OpenRegister; 503 if unconfigured).
		try {
			$themeConfig = $this->getThemeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		if ($this->request->getParam('featured') === 'true') {
			return $this->publicJsonResponse(data: $this->featuredSubjects(themeConfig: $themeConfig));
		}

		$result = $this->getObjectService()->searchObjectsPaginated(
			$this->scopedSearchQuery(objectConfig: $themeConfig),
			_rbac: true,
			_multitenancy: false
		);

		// Visibility governed by RBAC on the search above (_rbac: true); rbac=true
		// enforces schema authorization, multi=false allows public theme access.
		return $this->publicJsonResponse(data: $this->paginatedResponseData(result: $result));
	}//end index()

	/**
	 * Get a specific theme by its ID.
	 *
	 * @param string|integer $id The ID of the theme to retrieve.
	 *
	 * @return JSONResponse The JSON response containing the theme details.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/content-management/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string|int $id): JSONResponse {
		// Get theme configuration from settings (resolved via OpenRegister; 503 if
		// unconfigured), exactly as index() does.
		try {
			$themeConfig = $this->getThemeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$data = $this->findSubject(id: $id, themeConfig: $themeConfig);

		// An identifier that is not a theme and one that does not exist at all are the
		// same answer here. Unhandled, the lookup surfaced as a 500 rendering Nextcloud's
		// HTML error page on a public JSON route.
		if ($data === null) {
			return new JSONResponse(['error' => 'Not Found'], 404);
		}

		$subjectId = $this->subjectId(subject: $data);
		$data['publicationsUrl'] = '/index.php/apps/opencatalogi/api/themes/'.rawurlencode($subjectId).'/publications';
		$data['publicationCount'] = $this->subjects->countPublic(subjectId: $subjectId);

		return $this->publicJsonResponse(data: $data);
	}//end show()

	/**
	 * The publications filed under a subject, as the anonymous public read returns them.
	 *
	 * @param string $id The subject's id, uuid or slug.
	 *
	 * @return JSONResponse The paginated publications, or 404 for an unknown subject.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-a-subject-lists-what-is-filed-under-it-req-sub-003
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function publications(string $id): JSONResponse {
		try {
			$themeConfig = $this->getThemeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$subject = $this->findSubject(id: $id, themeConfig: $themeConfig);
		if ($subject === null) {
			return new JSONResponse(['error' => 'Not Found'], 404);
		}

		return $this->publicJsonResponse(
			data: $this->subjects->listPublic(
				subjectId: $this->subjectId(subject: $subject),
				limit: (int)($this->request->getParam('_limit') ?? 20),
				page: (int)($this->request->getParam('_page') ?? 1)
			)
		);
	}//end publications()

	/**
	 * The featured subjects, ordered by `featuredOrder` then `title`, each with
	 * the count of its public publications (REQ-SUB-002).
	 *
	 * @param array<string, string> $themeConfig The theme register and schema.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int}
	 *
	 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-the-featured-subjects-are-served-for-the-portal-req-sub-002
	 */
	private function featuredSubjects(array $themeConfig): array {
		$result = $this->getObjectService()->searchObjectsPaginated(
			[
				'@self' => ['register' => $themeConfig['register'], 'schema' => $themeConfig['schema']],
				'featured' => true,
				'_limit' => self::FEATURED_LIMIT,
			],
			_rbac: true,
			_multitenancy: false
		);

		$subjects = [];
		foreach (($result['results'] ?? []) as $row) {
			$subject = $this->subjectData(row: $row);
			if (($subject['featured'] ?? false) !== true) {
				continue;
			}

			$featured = [];
			foreach (self::FEATURED_KEYS as $key) {
				$featured[$key] = ($subject[$key] ?? null);
			}

			$featured['id'] = $this->subjectId(subject: $subject);
			$featured['publicationCount'] = $this->subjects->countPublic(subjectId: $featured['id']);
			$subjects[] = $featured;
		}

		usort(
			$subjects,
			static fn (array $left, array $right): int => [(int)($left['featuredOrder'] ?? PHP_INT_MAX), (string)($left['title'] ?? '')]
				<=> [(int)($right['featuredOrder'] ?? PHP_INT_MAX), (string)($right['title'] ?? '')]
		);

		return ['results' => $subjects, 'total' => count($subjects)];
	}//end featuredSubjects()

	/**
	 * A subject by id, uuid or slug, inside the theme register and schema, or null.
	 *
	 * @param string|integer        $id          The identifier.
	 * @param array<string, string> $themeConfig The theme register and schema.
	 *
	 * @return array<string, mixed>|null The subject.
	 *
	 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-a-subject-lists-what-is-filed-under-it-req-sub-003
	 */
	private function findSubject(string|int $id, array $themeConfig): ?array {
		// Scope the lookup to the theme register/schema. Without them, find() falls back
		// to OpenRegister's findAcrossAllMagicTables() path and resolves the identifier in
		// ANY register on the instance, and this route is @PublicPage, so that was an
		// unauthenticated read of arbitrary objects.
		//
		// `_rbac: true` was never sufficient on its own: OpenRegister grants read by
		// default on a schema that declares no authorization block, which is the state of
		// most registers on a shared instance.
		try {
			$theme = $this->getObjectService()->find(
				$id,
				[],
				false,
				$themeConfig['register'],
				$themeConfig['schema'],
				_rbac: true,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			$theme = null;
		}

		if ($theme === null && is_string($id) === true && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id) === 1) {
			$bySlug = $this->getObjectService()->searchObjectsPaginated(
				[
					'@self' => ['register' => $themeConfig['register'], 'schema' => $themeConfig['schema']],
					'slug' => $id,
					'_limit' => 1,
				],
				_rbac: true,
				_multitenancy: false
			);
			// Only a row whose slug is exactly the identifier answers: a filter the
			// register does not apply must never resolve another subject.
			$candidate = $this->subjectData(row: ($bySlug['results'][0] ?? null));
			if (($candidate['slug'] ?? null) === $id) {
				$theme = $candidate;
			}
		}

		if ($theme === null) {
			return null;
		}

		return $this->subjectData(row: $theme);
	}//end findSubject()

	/**
	 * A subject row as an array.
	 *
	 * @param mixed $row An OpenRegister row: an entity or an array.
	 *
	 * @return array<string, mixed> The subject.
	 */
	private function subjectData(mixed $row): array {
		if ($row instanceof \OCP\AppFramework\Db\Entity) {
			return $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end subjectData()

	/**
	 * The uuid publications file a subject under.
	 *
	 * @param array<string, mixed> $subject The subject.
	 *
	 * @return string The uuid, or an empty string when the row carries none.
	 */
	private function subjectId(array $subject): string {
		return (string)($subject['@self']['id'] ?? ($subject['id'] ?? ''));
	}//end subjectId()
}//end class
