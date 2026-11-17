<?php

/**
 * Validates a rendered DiWoo document against the DiWoo metadata XSD the app ships.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Http\XMLResponse;

/**
 * Checks one sitemap `url` entry's `diwoo:Document` against the shipped XSD (REQ-WNO-002).
 *
 * The XSD files in `lib/Settings/diwoo/` are verbatim copies of the DiWoo metadata
 * schema for {@see StandardsVersionService::DIWOO_VERSION}. The schema imports MDTO
 * by its absolute URL; the external entity loader maps that URL to the shipped copy
 * and refuses every other location outside that directory, so validating never
 * reaches the network. A schema that cannot be loaded is an error, never a pass: the
 * document is then held back, which is the safe state.
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-diwoo-output-is-validated-as-it-is-generated-against-the-current-standard-req-wno-002
 */
class DiwooXsdValidator
{

    /**
     * The absolute URL the DiWoo metadata XSD imports MDTO from.
     *
     * @var string
     */
    public const MDTO_XSD_URL = 'https://www.nationaalarchief.nl/mdto/MDTO-XML1.0.1.xsd';

    /**
     * The directory with the shipped XSD files.
     *
     * @var string
     */
    private readonly string $directory;


    /**
     * Constructor.
     *
     * @param string|null $directory The XSD directory; the shipped `lib/Settings/diwoo` when null.
     */
    public function __construct(?string $directory=null)
    {
        $this->directory = ($directory ?? dirname(__DIR__).'/Settings/diwoo');

    }//end __construct()


    /**
     * The XSD errors of one sitemap `url` entry, empty when its DiWoo document is valid.
     *
     * The entry is rendered the way the sitemap renders it (XMLResponse, inside the
     * sitemap `urlset` with the DiWoo namespace), the `diwoo:Document` is taken out on
     * its own and validated, as the XSD has `Document` as its root element.
     *
     * @param array<string, mixed> $entry The url entry: loc, lastmod and `diwoo:Document`.
     *
     * @return list<string> The XSD messages, empty when valid.
     *
     * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-diwoo-output-is-validated-as-it-is-generated-against-the-current-standard-req-wno-002
     */
    public function entryErrors(array $entry): array
    {
        $urlset = [
            '@root'       => 'urlset',
            '@attributes' => [
                'xmlns'       => StandardsVersionService::SITEMAP_NAMESPACE,
                'xmlns:diwoo' => StandardsVersionService::DIWOO_NAMESPACE,
            ],
            'url'         => [$entry],
        ];
        $xml = (new XMLResponse(data: $urlset))->render();

        $errors = $this->documentErrors(xml: $xml);
        if ($errors === []) {
            return ['the entry holds no diwoo:Document'];
        }

        return array_merge(...$errors);

    }//end entryErrors()


    /**
     * The XSD errors of every `diwoo:Document` in a rendered XML text, one list per document.
     *
     * @param string $xml The rendered XML, a sitemap page or a single document.
     *
     * @return list<list<string>> Per document in order, its XSD messages; empty when the text holds none.
     *
     * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-diwoo-output-is-validated-as-it-is-generated-against-the-current-standard-req-wno-002
     */
    public function documentErrors(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        libxml_set_external_entity_loader($this->entityLoader(...));
        try {
            $dom = new \DOMDocument();
            if ($xml === '' || $dom->loadXML($xml) === false) {
                return [self::messages(fallback: 'the XML cannot be parsed')];
            }

            $errors = [];
            foreach ($dom->getElementsByTagNameNS(StandardsVersionService::DIWOO_NAMESPACE, 'Document') as $document) {
                $errors[] = $this->validateOne(document: $document);
            }

            return $errors;
        } finally {
            libxml_clear_errors();
            libxml_set_external_entity_loader(null);
            libxml_use_internal_errors($previous);
        }//end try

    }//end documentErrors()


    /**
     * The XSD messages of one `diwoo:Document` element.
     *
     * @param \DOMElement $document The element.
     *
     * @return list<string> The messages, empty when valid.
     */
    private function validateOne(\DOMElement $document): array
    {
        $one = new \DOMDocument();
        $one->appendChild($one->importNode($document, true));
        // Reparse, so the namespace declarations sit on the new root as the XSD reads them.
        $one->loadXML((string) $one->saveXML());
        libxml_clear_errors();
        // An unloadable schema raises a PHP warning; it is kept as a message, as the result is.
        $warning = null;
        set_error_handler(
            static function (int $severity, string $message) use (&$warning): bool {
                $warning = $message;
                return true;
            }
        );
        try {
            $valid = $one->schemaValidate($this->directory.'/diwoo-metadata.xsd');
        } finally {
            restore_error_handler();
        }

        if ($valid === true) {
            return [];
        }

        $messages = self::messages(fallback: 'the document does not validate against the DiWoo XSD');
        if ($warning !== null) {
            $messages[] = trim(preg_replace('/^DOMDocument::schemaValidate\(\): /', '', $warning) ?? $warning);
        }

        return $messages;

    }//end validateOne()


    /**
     * The messages libxml collected, or the fallback when it collected none.
     *
     * @param string $fallback The message when libxml gave none.
     *
     * @return list<string> At least one message.
     */
    private static function messages(string $fallback): array
    {
        $messages = array_values(
            array_filter(
                array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors()),
                static fn (string $message): bool => $message !== ''
            )
        );
        libxml_clear_errors();

        if ($messages === []) {
            return [$fallback];
        }

        return $messages;

    }//end messages()


    /**
     * Resolve an external entity: the MDTO URL and files of the XSD directory only.
     *
     * @param string|null $public The public id.
     * @param string      $system The system id.
     *
     * @return string|null The local path, or null to refuse.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) The loader signature libxml calls.
     */
    private function entityLoader(?string $public, string $system): ?string
    {
        if ($system === self::MDTO_XSD_URL) {
            return $this->directory.'/MDTO-XML1.0.1.xsd';
        }

        $directory = realpath($this->directory);
        $path      = realpath($system);
        if ($directory !== false && $path !== false && str_starts_with($path, $directory.DIRECTORY_SEPARATOR) === true) {
            return $path;
        }

        return null;

    }//end entityLoader()


}//end class
