<?php

class UserService 
{
    // Function with multiple parameters but defaults
    public function createUser(string $name, int $age = 25, bool $active = true): string
    {
        return "User: {$name}, Age: {$age}, Active: " . ($active ? 'Yes' : 'No');
    }
    
    // Function that benefits from named parameters even with one argument
    public function findUserById(int $userId): ?object
    {
        return null; // Mock implementation
    }
    
    public function setUserStatus(bool $active): void
    {
        // Mock implementation
    }
}

$service = new UserService();

// These SHOULD trigger warnings (single parameter calls that could benefit from named params)
$user1 = $service->createUser('John');  // Only passing name, age/active use defaults
$user2 = $service->findUserById(12345);  // ID could be more explicit with named param
$service->setUserStatus(true);  // Boolean parameter should be explicit

// These should NOT trigger warnings (using named parameters)
$user3 = $service->createUser(name: 'Jane');
$user4 = $service->findUserById(userId: 67890);
$service->setUserStatus(active: false);

// These should NOT trigger warnings (built-in functions)
echo strlen('hello');
$count = count([1, 2, 3]); 