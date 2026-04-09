<?php

declare(strict_types=1);

namespace Unit\Exception;

use OCA\OpenCatalogi\Exception\DirectoryUrlException;
use PHPUnit\Framework\TestCase;

class DirectoryUrlExceptionTest extends TestCase
{
    public function testExtendsException(): void
    {
        $exception = new DirectoryUrlException();

        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function testConstructorWithMessage(): void
    {
        $exception = new DirectoryUrlException('Invalid URL');

        $this->assertSame('Invalid URL', $exception->getMessage());
    }

    public function testConstructorWithCode(): void
    {
        $exception = new DirectoryUrlException('Error', 400);

        $this->assertSame(400, $exception->getCode());
    }

    public function testConstructorWithPrevious(): void
    {
        $previous = new \RuntimeException('Root cause');
        $exception = new DirectoryUrlException('Wrapped', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testSetMessage(): void
    {
        $exception = new DirectoryUrlException('Original');
        $exception->setMessage('Updated message');

        $this->assertSame('Updated message', $exception->getMessage());
    }

    public function testSetMessageToEmpty(): void
    {
        $exception = new DirectoryUrlException('Original');
        $exception->setMessage('');

        $this->assertSame('', $exception->getMessage());
    }
}
