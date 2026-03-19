<?php
/**
 * Text response for plain text output.
 *
 * @category Http
 * @package  OCA\OpenCatalogi\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Http;

use OCP\AppFramework\Http\Response;

/**
 * A simple response for plain text data.
 *
 * @template-extends Response<int, array<string, mixed>>
 */
class TextResponse extends Response
{

    /**
     * The text to be returned.
     *
     * @var string
     */
    protected string $text;

    /**
     * Constructor for TextResponse.
     *
     * @param string                $text    The text to return.
     * @param integer               $status  HTTP status code, defaults to 200.
     * @param array<string, string> $headers Additional headers.
     */
    public function __construct(string $text='', int $status=200, array $headers=[])
    {
        parent::__construct($status);

        $this->text = $text;

        // Add custom headers.
        foreach ($headers as $name => $value) {
            $this->addHeader(name: $name, value: $value);
        }

        // Set content type header.
        $this->addHeader(name: 'Content-Type', value: 'text/plain; charset=utf-8');

    }//end __construct()

    /**
     * Returns the rendered text.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->text;

    }//end render()
}//end class
