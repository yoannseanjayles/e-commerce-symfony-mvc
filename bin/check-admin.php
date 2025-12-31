#!/usr/bin/env php
<?php

use App\Entity\Users;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new \App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();

$entityManager = $container->get('doctrine')->getManager();

echo "Checking for admin user...\n";
$admin = $entityManager->getRepository(Users::class)->findOneBy(['email' => 'admin@admin.com']);

if ($admin) {
    echo "✅ Admin user found!\n";
    echo "ID: " . $admin->getId() . "\n";
    echo "Email: " . $admin->getEmail() . "\n";
    echo "Roles: " . json_encode($admin->getRoles()) . "\n";
    echo "Password hash: " . substr($admin->getPassword(), 0, 20) . "...\n";
} else {
    echo "❌ Admin user NOT found!\n";
    echo "Attempting to create admin user now...\n\n";
    
    try {
        $admin = new Users();
        $admin->setEmail('admin@admin.com');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setFirstname('Super');
        $admin->setLastname('Admin');
        $admin->setAddress('Admin Address');
        $admin->setZipcode('00000');
        $admin->setCity('Admin City');
        $admin->setPassword('$2y$13$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
        
        $entityManager->persist($admin);
        $entityManager->flush();
        
        echo "✅ Admin user created successfully!\n";
        echo "Email: admin@admin.com\n";
        echo "Password: password\n";
    } catch (\Exception $e) {
        echo "❌ Error creating admin: " . $e->getMessage() . "\n";
    }
}
