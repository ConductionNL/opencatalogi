<?php
/**
 * DCAT response for serialized RDF output.
 *
 * Renders a pre-serialized DCAT-AP-NL document (JSON-LD, Turtle, or RDF/XML) as
 * a raw body with the negotiated Content-Type and harvester caching headers.
 *
 * @category Http
 * @package  OCA\OpenCatalogi\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Http;

use OCP\AppFramework\Http\Response;

/**
 * A response for serialized DCAT documents.
 *
 * @psalm-suppress MissingTemplateParam
 *
 * @spec exclude HTTP response plumbing — renders a pre-serialized body with the
 *       negotiated Content-Type; no domain behaviour (mirrors XMLResponse/TextResponse).
 */
class DcatResponse extends Response
{

    /**
     * The serialized DCAT document body.
     *
     * @var string
     */
    protected string $body;

    /**
     * Constructor for DcatResponse.
     *
     * @param string                $body        The serialized document body.
     * @param string                $contentType The MIME type for the serialization.
     * @param integer               $status      HTTP status code, defaults to 200.
     * @param array<string, string> $headers     Additional headers (CORS, caching, etc.).
     */
    public function __construct(string $body='', string $contentType='application/ld+json', int $status=200, array $headers=[])
    {
        // @phpstan-ignore argument.type
        parent::__construct($status);

        $this->body = $body;

        foreach ($headers as $name => $value) {
            $this->addHeader(name: $name, value: $value);
        }

        // Content-Type is set last so it is authoritative for the negotiated format.
        $this->addHeader(name: 'Content-Type', value: $contentType.'; charset=utf-8');

    }//end __construct()

    /**
     * Returns the serialized document body.
     *
     * @return string
     *
     * @spec exclude HTTP response plumbing — returns the pre-serialized body.
     */
    public function render(): string
    {
        return $this->body;

    }//end render()
}//end class
