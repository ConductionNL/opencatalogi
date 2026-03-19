<?php

declare(strict_types=1);

namespace Unit\Flow\Operations;

use OCA\OpenCatalogi\Flow\Operations\AutomatedPublishing;
use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\IRuleMatcher;
use PHPUnit\Framework\TestCase;

class AutomatedPublishingTest extends TestCase
{
    private AutomatedPublishing $operation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operation = new AutomatedPublishing();
    }

    // --- getDisplayName ---

    public function testGetDisplayName(): void
    {
        $this->assertSame('Automated publishing', $this->operation->getDisplayName());
    }

    public function testGetDisplayNameReturnsString(): void
    {
        $this->assertIsString($this->operation->getDisplayName());
    }

    public function testGetDisplayNameNotEmpty(): void
    {
        $this->assertNotEmpty($this->operation->getDisplayName());
    }

    // --- getDescription ---

    public function testGetDescription(): void
    {
        $this->assertSame(
            'Automatically publish publications if they meet predefined parameters',
            $this->operation->getDescription()
        );
    }

    public function testGetDescriptionReturnsString(): void
    {
        $this->assertIsString($this->operation->getDescription());
    }

    public function testGetDescriptionNotEmpty(): void
    {
        $this->assertNotEmpty($this->operation->getDescription());
    }

    // --- getIcon ---

    public function testGetIconReturnsString(): void
    {
        // \OC::$server is available in the Nextcloud test container.
        $icon = $this->operation->getIcon();
        $this->assertIsString($icon);
        $this->assertNotEmpty($icon);
    }

    // --- validateOperation ---

    public function testValidateOperationDoesNotThrow(): void
    {
        // validateOperation is a no-op; verify it doesn't throw
        $this->operation->validateOperation('test', [], 'op-string');

        // If we get here, no exception was thrown
        $this->assertTrue(true);
    }

    public function testValidateOperationWithEmptyName(): void
    {
        $this->operation->validateOperation('', [], '');
        $this->assertTrue(true);
    }

    public function testValidateOperationWithChecks(): void
    {
        $checks = [
            ['class' => 'SomeCheck', 'operator' => 'is', 'value' => 'test'],
        ];
        $this->operation->validateOperation('publish', $checks, 'auto-publish');
        $this->assertTrue(true);
    }

    public function testValidateOperationReturnType(): void
    {
        $reflection = new \ReflectionMethod(AutomatedPublishing::class, 'validateOperation');
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }

    // --- isAvailableForScope ---

    public function testIsAvailableForScopeZero(): void
    {
        $this->assertFalse($this->operation->isAvailableForScope(0));
    }

    public function testIsAvailableForScopeOne(): void
    {
        $this->assertFalse($this->operation->isAvailableForScope(1));
    }

    public function testIsAvailableForScopeTwo(): void
    {
        $this->assertFalse($this->operation->isAvailableForScope(2));
    }

    public function testIsAvailableForScopeNegative(): void
    {
        $this->assertFalse($this->operation->isAvailableForScope(-1));
    }

    public function testIsAvailableForScopeLargeNumber(): void
    {
        $this->assertFalse($this->operation->isAvailableForScope(999));
    }

    public function testIsAvailableForScopeReturnsBool(): void
    {
        $result = $this->operation->isAvailableForScope(1);
        $this->assertIsBool($result);
    }

    public function testIsAvailableForScopeAlwaysFalse(): void
    {
        // The current implementation returns false for all scopes.
        // Test a range of values to confirm.
        for ($scope = 0; $scope <= 10; $scope++) {
            $this->assertFalse(
                $this->operation->isAvailableForScope($scope),
                "Expected false for scope {$scope}"
            );
        }
    }

    // --- onEvent ---

    public function testOnEventDoesNotThrow(): void
    {
        $event = $this->createMock(Event::class);
        $ruleMatcher = $this->createMock(IRuleMatcher::class);

        $this->operation->onEvent('test.event', $event, $ruleMatcher);

        // No-op method — verify it doesn't throw
        $this->assertTrue(true);
    }

    public function testOnEventWithEmptyEventName(): void
    {
        $event = $this->createMock(Event::class);
        $ruleMatcher = $this->createMock(IRuleMatcher::class);

        $this->operation->onEvent('', $event, $ruleMatcher);
        $this->assertTrue(true);
    }

    public function testOnEventReturnType(): void
    {
        $reflection = new \ReflectionMethod(AutomatedPublishing::class, 'onEvent');
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }

    // --- Interface compliance ---

    public function testImplementsIOperation(): void
    {
        $this->assertInstanceOf(\OCP\WorkflowEngine\IOperation::class, $this->operation);
    }

    public function testAllInterfaceMethodsExist(): void
    {
        $this->assertTrue(method_exists($this->operation, 'getDisplayName'));
        $this->assertTrue(method_exists($this->operation, 'getDescription'));
        $this->assertTrue(method_exists($this->operation, 'getIcon'));
        $this->assertTrue(method_exists($this->operation, 'validateOperation'));
        $this->assertTrue(method_exists($this->operation, 'isAvailableForScope'));
        $this->assertTrue(method_exists($this->operation, 'onEvent'));
    }
}
