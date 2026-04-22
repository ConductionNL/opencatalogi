<?php

declare(strict_types=1);

namespace Unit\Sections;

use OCA\OpenCatalogi\Sections\OpenCatalogiAdmin;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class OpenCatalogiAdminTest extends TestCase
{
    private OpenCatalogiAdmin $section;
    private IL10N $l10n;
    private IURLGenerator $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->l10n = $this->createMock(IL10N::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);

        $this->l10n->method('t')->willReturnCallback(fn ($text) => $text);

        $this->section = new OpenCatalogiAdmin($this->l10n, $this->urlGenerator);
    }

    public function testGetIcon(): void
    {
        $this->urlGenerator->method('imagePath')
            ->with('opencatalogi', 'app-dark.svg')
            ->willReturn('/apps/opencatalogi/img/app-dark.svg');

        $this->assertSame('/apps/opencatalogi/img/app-dark.svg', $this->section->getIcon());
    }

    public function testGetID(): void
    {
        $this->assertSame('opencatalogi', $this->section->getID());
    }

    public function testGetName(): void
    {
        $this->assertSame('Open Catalogi', $this->section->getName());
    }

    public function testGetPriority(): void
    {
        $this->assertSame(97, $this->section->getPriority());
    }
}
