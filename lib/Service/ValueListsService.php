<?php

/**
 * OpenCatalogi Value Lists Service.
 *
 * Every entry of every controlled list OpenCatalogi uses, in one shape, for
 * the integrators' page and its JSON twin: the TOOI and DiWoo lists behind
 * {@see TooiVocabularyService}, the DCAT lists behind {@see DcatVocabularyService}
 * and the Woo refusal grounds behind {@see Woo\RefusalGrounds}. It reads each
 * list through the service that owns it, so a list that moves to OpenRegister's
 * vocabulary register moves here with it.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-every-list-entry-is-on-one-page-for-integrators-req-wvc-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Service\Woo\RefusalGrounds;
use OCA\OpenCatalogi\Service\Woo\RefusalGroundsUnavailableException;
use OCP\IAppConfig;

/**
 * Lists every controlled-list entry as `{scheme, schemeUri, code, label, uri, status, lastRefreshAt}`.
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
class ValueListsService {

	/**
	 * The app config key under which a refresh records its moment per scheme.
	 */
	public const REFRESH_STATE_KEY = 'tooi_scheme_state';

	/**
	 * The schemes this page lists, in the order it lists them.
	 */
	public const SCHEMES = [
		'informatiecategorieen',
		'soortHandeling',
		'documentsoorten',
		'talen',
		'hvdCategories',
		'dataThemes',
		'weigeringsgronden',
	];

	/**
	 * The TOOI set of the Woo information categories.
	 */
	private const INFORMATIECATEGORIEEN_SET = 'https://identifier.overheid.nl/tooi/set/scw_woo_informatiecategorieen';

	/**
	 * The bundled TOOI/DiWoo lists, for the scheme URIs of documentsoorten and talen.
	 */
	private const TOOI_BUNDLE = __DIR__ . '/../Settings/tooi_waardelijsten.json';

	/**
	 * The EU data-theme authority table.
	 */
	private const DATA_THEME_SET = 'http://publications.europa.eu/resource/authority/data-theme';

	/**
	 * Constructor.
	 *
	 * @param TooiVocabularyService $tooi      The TOOI and DiWoo lists.
	 * @param DcatVocabularyService $dcat      The DCAT lists.
	 * @param RefusalGrounds        $grounds   The Woo refusal grounds.
	 * @param IAppConfig            $appConfig Where a refresh records its moment per scheme.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TooiVocabularyService $tooi,
		private readonly DcatVocabularyService $dcat,
		private readonly RefusalGrounds $grounds,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every entry, optionally of one scheme.
	 *
	 * An unreadable list is left out rather than answered empty-and-fine; the
	 * schemes that could not be read are named in `unavailable`.
	 *
	 * @param string|null $scheme One of {@see self::SCHEMES}, or null for all.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, schemes: array<int, string>, unavailable: array<int, string>}
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-every-list-entry-is-on-one-page-for-integrators-req-wvc-004
	 */
	public function entries(?string $scheme = null): array {
		$schemes = self::SCHEMES;
		if ($scheme !== null && $scheme !== '') {
			$schemes = array_values(array_intersect(self::SCHEMES, [$scheme]));
		}

		$refreshed = $this->refreshState();
		$results = [];
		$unavailable = [];
		foreach ($schemes as $name) {
			try {
				$rows = $this->rowsOf(scheme: $name);
			} catch (RefusalGroundsUnavailableException $e) {
				$unavailable[] = $name;
				continue;
			}

			foreach ($rows as $row) {
				$row['lastRefreshAt'] = ($refreshed[$name] ?? null);
				$results[] = $row;
			}
		}

		return [
			'results' => $results,
			'total' => count($results),
			'schemes' => $schemes,
			'unavailable' => $unavailable,
		];
	}//end entries()

	/**
	 * The entries of one scheme, without their refresh moment.
	 *
	 * @param string $scheme The scheme.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @throws RefusalGroundsUnavailableException When the refusal grounds cannot be read.
	 */
	private function rowsOf(string $scheme): array {
		return match ($scheme) {
			'informatiecategorieen' => $this->keyedRows(
				scheme: $scheme,
				schemeUri: self::INFORMATIECATEGORIEEN_SET,
				list: $this->tooi->informatiecategorieList()
			),
			'soortHandeling' => $this->uriRows(scheme: $scheme, schemeUri: '', list: $this->tooi->soortHandelingList()),
			'documentsoorten' => $this->memberRows(
				scheme: $scheme,
				schemeUri: $this->bundledSetUri(key: 'documentsoorten'),
				list: $this->tooi->documentsoortList()
			),
			'talen' => $this->memberRows(
				scheme: $scheme,
				schemeUri: $this->bundledSetUri(key: 'talen'),
				list: $this->tooi->languageList()
			),
			'hvdCategories' => $this->keyedRows(
				scheme: $scheme,
				schemeUri: '',
				list: $this->dcat->hvdCategoryList()
			),
			'dataThemes' => $this->uriRows(scheme: $scheme, schemeUri: self::DATA_THEME_SET, list: $this->dcat->dataThemeList()),
			'weigeringsgronden' => $this->groundRows(scheme: $scheme),
			default => [],
		};
	}//end rowsOf()

	/**
	 * Rows of a list keyed by code, each `{uri, label}`.
	 *
	 * @param string                                          $scheme    The scheme.
	 * @param string                                          $schemeUri The scheme's URI, or empty.
	 * @param array<string, array{uri: string, label: string}> $list     The list.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function keyedRows(string $scheme, string $schemeUri, array $list): array {
		$rows = [];
		foreach ($list as $code => $entry) {
			$rows[] = $this->row(
				scheme: $scheme,
				schemeUri: $schemeUri,
				code: (string)$code,
				label: (string)$entry['label'],
				uri: (string)$entry['uri']
			);
		}

		return $rows;
	}//end keyedRows()

	/**
	 * Rows of a list mapping a code to its URI; the code is the label.
	 *
	 * @param string                $scheme    The scheme.
	 * @param string                $schemeUri The scheme's URI, or empty.
	 * @param array<string, string> $list      The list.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function uriRows(string $scheme, string $schemeUri, array $list): array {
		$rows = [];
		foreach ($list as $code => $uri) {
			$rows[] = $this->row(scheme: $scheme, schemeUri: $schemeUri, code: (string)$code, label: (string)$code, uri: (string)$uri);
		}

		return $rows;
	}//end uriRows()

	/**
	 * Rows of a list of `{code, label, uri}` members.
	 *
	 * @param string                                                 $scheme    The scheme.
	 * @param string                                                 $schemeUri The scheme's URI, or empty.
	 * @param array<int, array{code: string, label: string, uri: string}> $list The members.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function memberRows(string $scheme, string $schemeUri, array $list): array {
		$rows = [];
		foreach ($list as $member) {
			$rows[] = $this->row(
				scheme: $scheme,
				schemeUri: $schemeUri,
				code: (string)$member['code'],
				label: (string)$member['label'],
				uri: (string)$member['uri']
			);
		}

		return $rows;
	}//end memberRows()

	/**
	 * The refusal grounds, the statute article as their URI.
	 *
	 * @param string $scheme The scheme.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws RefusalGroundsUnavailableException When the grounds cannot be read.
	 */
	private function groundRows(string $scheme): array {
		$rows = [];
		foreach ($this->grounds->list() as $ground) {
			$rows[] = $this->row(
				scheme: $scheme,
				schemeUri: '',
				code: (string)$ground['code'],
				label: (string)($ground['label'] ?? ''),
				uri: (string)($ground['legalSource'] ?? ''),
				status: (string)($ground['status'] ?? 'active')
			);
		}

		return $rows;
	}//end groundRows()

	/**
	 * One row.
	 *
	 * @param string $scheme    The scheme.
	 * @param string $schemeUri The scheme's URI, or empty.
	 * @param string $code      The entry's code.
	 * @param string $label     The entry's label.
	 * @param string $uri       The entry's resource URI.
	 * @param string $status    active or deprecated.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(string $scheme, string $schemeUri, string $code, string $label, string $uri, string $status = 'active'): array {
		return [
			'scheme' => $scheme,
			'schemeUri' => $schemeUri,
			'code' => $code,
			'label' => $label,
			'uri' => $uri,
			'status' => $status,
		];
	}//end row()

	/**
	 * The set URI the bundled TOOI file records for a list, or empty.
	 *
	 * @param string $key The list's key in the bundle.
	 *
	 * @return string The set URI.
	 */
	private function bundledSetUri(string $key): string {
		if (is_readable(self::TOOI_BUNDLE) === false) {
			return '';
		}

		$bundle = json_decode((string)file_get_contents(self::TOOI_BUNDLE), true);

		return (string)($bundle[$key]['list'] ?? '');
	}//end bundledSetUri()

	/**
	 * The moment of the last refresh per scheme, as a refresh records it.
	 *
	 * @return array<string, string> Scheme to ISO 8601 moment.
	 */
	private function refreshState(): array {
		$state = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::REFRESH_STATE_KEY, '{}'), true);
		if (is_array($state) === false) {
			return [];
		}

		$moments = [];
		foreach ($state as $scheme => $entry) {
			if (is_array($entry) === true && is_string($entry['lastRefreshAt'] ?? null) === true) {
				$moments[(string)$scheme] = $entry['lastRefreshAt'];
			}
		}

		return $moments;
	}//end refreshState()
}//end class
