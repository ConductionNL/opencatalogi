<?php

namespace OCA\OpenCatalogi\Http;

use OCP\AppFramework\Http\Response;

/**
 * A simple response for plain text data
 */
class TextResponse extends Response
{
    /** 
     * @var string The text to be returned 
     */
    protected string $text;

    /**
     * Constructor for TextResponse
     *
     * @param string $text The text to return
     * @param int $status HTTP status code, defaults to 200
     * @param array<string, string> $headers Additional headers
     */
    public function __construct(string $text = '', int $status = 200, array $headers = [])
    {
        parent::__construct($status);

        $this->text = $text;

        // Add custom headers
        foreach ($headers as $name => $value) {
            $this->addHeader($name, $value);
        }

        // Set content type header
        $this->addHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Returns the rendered text
     *
     * @return string
     */
    public function render(): string
    {
        return $this->text;
    }
}
