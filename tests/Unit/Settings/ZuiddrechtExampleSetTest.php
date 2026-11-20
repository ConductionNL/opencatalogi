<?php

/**
 * The Zuiddrecht example set resolves against the register this app ships.
 *
 * `lib/Settings/example-sets/zuiddrecht.json` is installed by OpenRegister's
 * example-set loader. Its placeholders are resolved the way the loader resolves
 * them, and every object is then validated against its schema in the merged
 * register (`publication_register.json` plus `register.d/`, the import's own
 * merge), with no key the schema does not declare. A register schema that
 * renames or drops a property the set fills makes this fail, instead of the
 * install.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/zuiddrecht-example-set/specs/example-set/spec.md
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Placeholders resolve, objects validate, no undeclared keys, files exist.
 */
class ZuiddrechtExampleSetTest extends TestCase {

	/**
	 * The app's settings folder.
	 *
	 * @var string
	 */
	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * The cast people the set may name, with the `uid` the loader fills in.
	 * From OpenRegister's `lib/Settings/example-sets/zuiddrecht.json`, `cast.people`.
	 *
	 * @var array<int, string>
	 */
	private const CAST_PEOPLE = [
		'sanne', 'henk', 'linda', 'fatima', 'janwillem', 'mark', 'karin', 'pieterbos', 'henkvisser', 'jvanwijk', 'marja',
		'keesdewit', 'daan', 'joris', 'lotte', 'esra', 'thomas', 'marloes', 'joost', 'ingrid', 'ruud', 'yara', 'hanneke',
		'tom', 'anouk', 'marleen', 'jamal', 'sannebakker', 'mehmet', 'pieter', 'hannabos', 'timmulder', 'fatmayildiz',
		'fatma', 'ilse', 'bram', 'petra', 'samira', 'noor', 'lars',
	];

	/**
	 * The OpenRegister organisations of the cast, by slug (`cast.openregisterOrganisations`).
	 *
	 * @var array<int, string>
	 */
	private const CAST_ORGANISATIONS = ['zuiddrecht', 'waterland', 'odzhz'];

	/**
	 * The set as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private static function set(): array {
		return json_decode((string)file_get_contents(self::SETTINGS . '/example-sets/zuiddrecht.json'), true, 512, JSON_THROW_ON_ERROR);
	}//end set()

	/**
	 * The merged register, by the import's own merge: `publication_register.json` with every
	 * `register.d/` fragment merged on in name order (lists concatenate, objects union).
	 *
	 * @return array{schemas: array<string, array<string, mixed>>, registers: array<string, array<int, string>>}
	 */
	private static function register(): array {
		$fragments = glob(self::SETTINGS . '/register.d/*.json');
		sort($fragments);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$document = json_decode((string)file_get_contents(self::SETTINGS . '/publication_register.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($fragments as $file) {
			$document = $merge->invoke(null, $document, json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
		}

		$registers = [];
		foreach (($document['components']['registers'] ?? []) as $slug => $register) {
			$registers[$slug] = array_values(array_unique((array)($register['schemas'] ?? [])));
		}

		return ['schemas' => (array)($document['components']['schemas'] ?? []), 'registers' => $registers];
	}//end register()

	/**
	 * A stable uuid for a name, standing in for an id the loader assigns.
	 *
	 * @param string $name The name.
	 *
	 * @return string
	 */
	private static function uuid(string $name): string {
		$hex = md5($name);
		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
	}//end uuid()

	/**
	 * What one placeholder stands for, typed as the loader types it; null when it cannot be filled.
	 *
	 * @param string               $inner    The text between the braces.
	 * @param array<int, string>   $keys     The keys of the set's objects.
	 * @param array<string, mixed> $register The merged register.
	 *
	 * @return mixed
	 */
	private static function stand_in(string $inner, array $keys, array $register): mixed {
		[$kind, $name] = array_pad(explode(':', $inner, 2), 2, '');
		return match ($kind) {
			'object' => (preg_match('/^(.+)\.id$/', $name, $m) === 1 && in_array($m[1], $keys, true) === true) ? self::uuid($m[1]) : null,
			'register' => isset($register['registers'][$name]) === true ? 1 : null,
			'schema' => self::schemaStandIn(name: $name, register: $register),
			'organisation' => in_array($name, self::CAST_ORGANISATIONS, true) === true ? self::uuid('organisation:' . $name) : null,
			'cast' => (preg_match('/^([a-z]+)\.uid$/', $name, $m) === 1 && in_array($m[1], self::CAST_PEOPLE, true) === true) ? $m[1] : null,
			'date' => (preg_match('/^-?\d+$/', $name) === 1) ? '2026-06-15' : null,
			'datetime' => (preg_match('/^-?\d+ \d{2}:\d{2}$/', $name) === 1) ? '2026-06-15T10:00:00+02:00' : null,
			default => null,
		};
	}//end stand_in()

	/**
	 * A schema placeholder (`register/schema`) stands for an id when the register lists that schema.
	 *
	 * @param string               $name     `register/schema`.
	 * @param array<string, mixed> $register The merged register.
	 *
	 * @return int|null
	 */
	private static function schemaStandIn(string $name, array $register): ?int {
		[$registerSlug, $schemaSlug] = array_pad(explode('/', $name, 2), 2, '');
		return in_array($schemaSlug, ($register['registers'][$registerSlug] ?? []), true) === true ? 2 : null;
	}//end schemaStandIn()

	/**
	 * Fill every placeholder in a value; the ones that cannot be filled go to `$gaps`.
	 *
	 * @param mixed                $value    The declared value.
	 * @param array<int, string>   $keys     The keys of the set's objects.
	 * @param array<string, mixed> $register The merged register.
	 * @param array<int, string>   $gaps     Collects the unfilled placeholders.
	 *
	 * @return mixed
	 */
	private static function fill(mixed $value, array $keys, array $register, array &$gaps): mixed {
		if (is_array($value) === true) {
			$filled = [];
			foreach ($value as $key => $item) {
				$filled[$key] = self::fill(value: $item, keys: $keys, register: $register, gaps: $gaps);
			}

			return $filled;
		}

		if (is_string($value) === false || str_contains($value, '{{') === false) {
			return $value;
		}

		if (preg_match('/^\{\{([^{}]+)\}\}$/', $value, $whole) === 1) {
			$found = self::stand_in(inner: trim($whole[1]), keys: $keys, register: $register);
			if ($found === null) {
				$gaps[] = $value;
				return $value;
			}

			return $found;
		}

		return preg_replace_callback(
			'/\{\{([^{}]+)\}\}/',
			static function (array $match) use ($keys, $register, &$gaps): string {
				$found = self::stand_in(inner: trim($match[1]), keys: $keys, register: $register);
				if ($found === null) {
					$gaps[] = $match[0];
					return $match[0];
				}

				return (string)$found;
			},
			$value
		);
	}//end fill()

	/**
	 * A register schema as JSON Schema: OpenRegister's `$ref` marks a relation, stored as the related object's id.
	 *
	 * @param mixed $schema The register schema.
	 *
	 * @return mixed
	 */
	private static function asJsonSchema(mixed $schema): mixed {
		if (is_array($schema) === false) {
			return $schema;
		}

		unset($schema['$ref']);
		return array_map(static fn (mixed $part): mixed => self::asJsonSchema(schema: $part), $schema);
	}//end asJsonSchema()

	/**
	 * Keys in `$data` that its schema does not declare, as dotted paths, at every depth the schema describes.
	 *
	 * @param array<string, mixed> $data   The object or nested value.
	 * @param array<string, mixed> $schema Its schema.
	 * @param string               $path   The path so far.
	 *
	 * @return array<int, string>
	 */
	private static function undeclared(array $data, array $schema, string $path = ''): array {
		$properties = $schema['properties'] ?? null;
		if (is_array($properties) === false || array_is_list($data) === true) {
			return [];
		}

		$found = [];
		foreach ($data as $key => $value) {
			if (array_key_exists($key, $properties) === false) {
				$found[] = $path . $key;
				continue;
			}

			$items = ($properties[$key]['items'] ?? null);
			if (is_array($value) === true && array_is_list($value) === true && is_array($items) === true) {
				foreach ($value as $index => $item) {
					$found = array_merge($found, is_array($item) === true ? self::undeclared(data: $item, schema: $items, path: $path . $key . '.' . $index . '.') : []);
				}
			} else if (is_array($value) === true) {
				$found = array_merge($found, self::undeclared(data: $value, schema: (array)$properties[$key], path: $path . $key . '.'));
			}
		}

		return $found;
	}//end undeclared()

	/**
	 * Every placeholder of the set resolves, against the cast, the set's own objects and the shipped register.
	 *
	 * @return void
	 */
	public function testEveryPlaceholderResolves(): void {
		$set = self::set();
		$register = self::register();
		$keys = array_column($set['objects'], 'key');

		$gaps = [];
		self::fill(value: $set['objects'], keys: $keys, register: $register, gaps: $gaps);

		$this->assertGreaterThan(20, count($keys));
		$this->assertSame([], array_values(array_unique($gaps)), 'placeholders the loader could not fill');
		$this->assertSame($keys, array_values(array_unique($keys)), 'two objects share a key');
	}//end testEveryPlaceholderResolves()

	/**
	 * Every object names a schema the merged register lists, satisfies it, and carries no key it does not declare.
	 *
	 * @return void
	 */
	public function testEveryObjectSatisfiesItsSchema(): void {
		$set = self::set();
		$register = self::register();
		$keys = array_column($set['objects'], 'key');
		$validator = new Validator();
		$validator->setMaxErrors(5);

		$failures = [];
		foreach ($set['objects'] as $object) {
			$where = $object['key'] . ' (' . $object['register'] . '/' . $object['schema'] . ')';
			if (in_array($object['schema'], ($register['registers'][$object['register']] ?? []), true) === false || isset($register['schemas'][$object['schema']]) === false) {
				$failures[] = $where . ': the register does not list this schema';
				continue;
			}

			$schema = $register['schemas'][$object['schema']];
			$gaps = [];
			$data = self::fill(value: $object['data'], keys: $keys, register: $register, gaps: $gaps);

			foreach (self::undeclared(data: $data, schema: $schema) as $key) {
				$failures[] = $where . ': undeclared key ' . $key;
			}

			$result = $validator->validate(
				json_decode((string)json_encode($data, JSON_THROW_ON_ERROR)),
				json_decode((string)json_encode(self::asJsonSchema(schema: $schema), JSON_THROW_ON_ERROR))
			);
			if ($result->isValid() === false) {
				$failures[] = $where . ': ' . json_encode((new ErrorFormatter())->format($result->error()), JSON_UNESCAPED_SLASHES);
			}
		}//end foreach

		$this->assertSame([], $failures);
	}//end testEveryObjectSatisfiesItsSchema()

	/**
	 * Every file an object lists is committed beside the set.
	 *
	 * @return void
	 */
	public function testEveryListedFileShips(): void {
		$missing = [];
		$listed = 0;
		foreach (self::set()['objects'] as $object) {
			foreach (($object['files'] ?? []) as $file) {
				$listed++;
				if (is_file(self::SETTINGS . '/example-sets/' . $file['path']) === false) {
					$missing[] = $file['path'];
				}
			}
		}

		$this->assertGreaterThan(0, $listed);
		$this->assertSame([], $missing);
	}//end testEveryListedFileShips()

	/**
	 * The check itself refuses what it must: an unknown placeholder, a key no schema declares, a schema the register lacks.
	 *
	 * @return void
	 */
	public function testTheCheckRefusesWhatItMust(): void {
		$register = self::register();
		$gaps = [];
		self::fill(value: ['a' => '{{object:nobody.id}}', 'b' => 'Op {{date:x}}', 'c' => '{{cast:stranger.uid}}', 'd' => '{{schema:publication/nothing}}'], keys: ['catalogus'], register: $register, gaps: $gaps);
		$this->assertSame(['{{object:nobody.id}}', '{{date:x}}', '{{cast:stranger.uid}}', '{{schema:publication/nothing}}'], $gaps);

		$theme = $register['schemas']['theme'];
		$this->assertSame(['colour'], self::undeclared(data: ['title' => 'Verkeer', 'colour' => 'red'], schema: $theme));
	}//end testTheCheckRefusesWhatItMust()
}//end class
