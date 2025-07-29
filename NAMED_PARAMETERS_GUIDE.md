# Named Parameters Enforcement Guide

## Overview

This project enforces the use of **named parameters** in PHP function calls to improve code readability and maintainability. Named parameters were introduced in PHP 8.0 and allow you to specify parameter names when calling functions.

## What are Named Parameters?

Named parameters allow you to pass arguments to a function by specifying the parameter name, rather than relying on the order of parameters.

### Examples

**❌ Bad (Positional Parameters):**
```php
$user = $service->createUser('John Doe', 30, true, 'admin', 'john@example.com');
```

**✅ Good (Named Parameters):**
```php
$user = $service->createUser(
    name: 'John Doe',
    age: 30, 
    active: true,
    role: 'admin',
    email: 'john@example.com'
);
```

## Benefits

1. **Readability**: It's immediately clear what each argument represents
2. **Maintainability**: Adding new parameters doesn't break existing calls
3. **Error Prevention**: Reduces mistakes from incorrect parameter order
4. **Self-Documenting**: The code explains itself without needing to check function signatures
5. **Default Parameter Clarity**: Even single-parameter calls benefit when the function has defaults
6. **Boolean Parameter Clarity**: Makes boolean parameters explicit (e.g., `active: true` vs just `true`)

## PHPCS Rules

Our `phpcs.xml` includes:

1. **Custom Sniff**: `CustomSniffs.Functions.NamedParameters` - Warns when functions are called without named parameters
2. **Documentation Requirements**: All function parameters must be documented
3. **Meaningful Names**: Encourages descriptive parameter names

## Usage Examples

### Function Calls
```php
// Instead of:
$result = $object->someMethod($registers, $type, $active);

// Use:
$result = $object->someMethod(
    registers: $registers,
    type: $type, 
    active: $active
);
```

### Single Parameter Calls (NEW!)
Even single-parameter calls benefit from named parameters:

```php
// Instead of:
$user = $service->createUser('John');        // Unclear if other params have defaults
$user = $service->findUserById(12345);       // Unclear what the number represents  
$service->setUserStatus(true);               // Unclear what true means

// Use:
$user = $service->createUser(name: 'John');
$user = $service->findUserById(userId: 12345);
$service->setUserStatus(active: true);
```

### Method Calls
```php
// Instead of:
$this->processData($data, 'json', true, ['validation' => true]);

// Use:
$this->processData(
    data: $data,
    format: 'json',
    strict: true,
    options: ['validation' => true]
);
```

## Exceptions

The custom sniff automatically skips common built-in functions that typically don't benefit from named parameters:

- `echo`, `print`, `var_dump`, `print_r`
- `count`, `strlen`, `empty`, `isset`
- `array_push`, `array_pop`, etc.

## Running PHPCS

To check your code:

```bash
phpcs --standard=phpcs.xml lib/
```

To automatically fix some issues:

```bash
phpcbf --standard=phpcs.xml lib/
```

## Migration Strategy

1. Start with new code - use named parameters for all new function calls
2. When modifying existing code, convert function calls to use named parameters
3. Focus on functions with 3+ parameters first
4. Use IDE refactoring tools when available

## IDE Support

Most modern IDEs support named parameters:

- **PhpStorm**: Automatic conversion and suggestions
- **VS Code**: With PHP extensions
- **Vim/Neovim**: With LSP plugins

## Requirements

- PHP 8.0 or higher
- PHP_CodeSniffer for linting 