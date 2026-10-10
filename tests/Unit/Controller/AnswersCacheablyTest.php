<?php

/**
 * The public cache headers on the public read answers (REQ-PAC-001, REQ-PAC-002).
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
 *
 * @spec openspec/changes/operations-public-api-cache-headers/specs/public-api-caching/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\AnswersCacheably;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A minimal host for the trait, shaped like the two public controllers.
 */
class CacheablyHost {
	use AnswersCacheably;

	/**
	 * @param IRequest           $request   The request.
	 * @param ContainerInterface $container The container the controllers resolve from.
	 * @param string             $appName   The app name.
	 */
	public function __construct(
		public IRequest $request,
		public ContainerInterface $container,
		public string $appName = 'opencatalogi',
	) {
	}

	/**
	 * Expose the trait method.
	 *
	 * @param JSONResponse $response The answer.
	 *
	 * @return JSONResponse The answer with its cache headers.
	 */
	public function answer(JSONResponse $response): JSONResponse {
		return $this->cacheableForCaller(response: $response);
	}
}

/**
 * Unit tests for AnswersCacheably.
 */
class AnswersCacheablyTest extends TestCase {

	/**
	 * Build a host for a caller.
	 *
	 * @param boolean     $signedIn     Whether the caller is signed in.
	 * @param string      $ifNoneMatch  The If-None-Match header.
	 * @param string|null $cacheSeconds The configured cache time, or null for the default.
	 * @param boolean     $brokenConfig Whether the container cannot answer.
	 *
	 * @return CacheablyHost The host.
	 */
	private function host(bool $signedIn, string $ifNoneMatch = '', ?string $cacheSeconds = null, bool $brokenConfig = false): CacheablyHost {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => (strtolower($name) === 'if-none-match' ? $ifNoneMatch : '')
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('isLoggedIn')->willReturn($signedIn);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($key === 'public_api_cache_seconds' && $cacheSeconds !== null ? $cacheSeconds : $default)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($session, $config, $brokenConfig) {
				if ($brokenConfig === true) {
					throw new \RuntimeException('not available');
				}

				return match ($id) {
					IUserSession::class => $session,
					IAppConfig::class => $config,
				};
			}
		);

		return new CacheablyHost($request, $container);
	}

	/**
	 * An anonymous 200 is public, carries a weak ETag over the body and Vary.
	 */
	public function testAnAnonymousAnswerIsPublicWithAnEtagAndVary(): void {
		$response = $this->host(signedIn: false)->answer(new JSONResponse(['results' => [['id' => 'a']]], Http::STATUS_OK));
		$headers = $response->getHeaders();

		$this->assertSame('public, max-age=60, must-revalidate', $headers['Cache-Control']);
		$this->assertMatchesRegularExpression('/^W\/"[0-9a-f]{40}"$/', $headers['ETag']);
		$this->assertSame('Origin, Accept-Language', $headers['Vary']);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * The same body gives the same tag, a different body a different one.
	 */
	public function testTheEtagFollowsTheBody(): void {
		$one = $this->host(signedIn: false)->answer(new JSONResponse(['a' => 1]))->getHeaders()['ETag'];
		$same = $this->host(signedIn: false)->answer(new JSONResponse(['a' => 1]))->getHeaders()['ETag'];
		$other = $this->host(signedIn: false)->answer(new JSONResponse(['a' => 2]))->getHeaders()['ETag'];

		$this->assertSame($one, $same);
		$this->assertNotSame($one, $other);
	}

	/**
	 * A matching If-None-Match answers 304 with the tag and no body.
	 */
	public function testAMatchingIfNoneMatchAnswers304(): void {
		$tag = $this->host(signedIn: false)->answer(new JSONResponse(['a' => 1]))->getHeaders()['ETag'];

		$response = $this->host(signedIn: false, ifNoneMatch: '"other", ' . $tag)->answer(new JSONResponse(['a' => 1]));

		$this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
		$this->assertSame($tag, $response->getHeaders()['ETag']);
		$this->assertSame('public, max-age=60, must-revalidate', $response->getHeaders()['Cache-Control']);
		$this->assertSame([], $response->getData());
	}

	/**
	 * A strong form of the same tag matches too (weak comparison, RFC 9110).
	 */
	public function testAStrongFormOfTheTagMatches(): void {
		$tag = $this->host(signedIn: false)->answer(new JSONResponse(['a' => 1]))->getHeaders()['ETag'];

		$response = $this->host(signedIn: false, ifNoneMatch: substr($tag, 2))->answer(new JSONResponse(['a' => 1]));

		$this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
	}

	/**
	 * A signed-in caller gets private, no-store and no tag (REQ-PAC-002).
	 */
	public function testASignedInAnswerIsPrivateWithoutEtag(): void {
		$response = $this->host(signedIn: true)->answer(new JSONResponse(['a' => 1]));
		$headers = $response->getHeaders();

		$this->assertSame('private, no-store', $headers['Cache-Control']);
		$this->assertArrayNotHasKey('ETag', $headers);
	}

	/**
	 * An error answer carries no public cache header.
	 */
	public function testAnErrorAnswerIsLeftAlone(): void {
		$response = $this->host(signedIn: false)->answer(new JSONResponse(['error' => 'Catalog not found'], Http::STATUS_NOT_FOUND));
		$headers = $response->getHeaders();

		$this->assertStringNotContainsString('public', (string)($headers['Cache-Control'] ?? ''));
		$this->assertArrayNotHasKey('ETag', $headers);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	/**
	 * The configured time is used, and zero switches the public headers off (REQ-PAC-003).
	 */
	public function testTheConfiguredTimeIsUsedAndZeroTurnsCachingOff(): void {
		$long = $this->host(signedIn: false, cacheSeconds: '300')->answer(new JSONResponse(['a' => 1]));
		$this->assertSame('public, max-age=300, must-revalidate', $long->getHeaders()['Cache-Control']);

		$off = $this->host(signedIn: false, cacheSeconds: '0')->answer(new JSONResponse(['a' => 1]));
		$this->assertStringNotContainsString('public', (string)($off->getHeaders()['Cache-Control'] ?? ''));
		$this->assertArrayNotHasKey('ETag', $off->getHeaders());
	}

	/**
	 * When the caller cannot be determined, the answer is treated as private.
	 */
	public function testAnUnknownCallerIsTreatedAsSignedIn(): void {
		$response = $this->host(signedIn: false, brokenConfig: true)->answer(new JSONResponse(['a' => 1]));

		$this->assertSame('private, no-store', $response->getHeaders()['Cache-Control']);
		$this->assertArrayNotHasKey('ETag', $response->getHeaders());
	}
}
