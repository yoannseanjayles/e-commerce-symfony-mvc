<?php

namespace App\Command;

use App\Entity\Users;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Create an admin user',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $emailQuestion = new Question('Email: ', 'admin@admin.com');
        $email = $helper->ask($input, $output, $emailQuestion);

        $passwordQuestion = new Question('Password: ');
        $passwordQuestion->setHidden(true);
        $password = $helper->ask($input, $output, $passwordQuestion);

        $firstnameQuestion = new Question('Firstname: ', 'Admin');
        $firstname = $helper->ask($input, $output, $firstnameQuestion);

        $lastnameQuestion = new Question('Lastname: ', 'Admin');
        $lastname = $helper->ask($input, $output, $lastnameQuestion);

        // Check if user already exists
        $existingUser = $this->entityManager->getRepository(Users::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            $io->error('User with this email already exists!');
            return Command::FAILURE;
        }

        // Create user
        $user = new Users();
        $user->setEmail($email);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setFirstname($firstname);
        $user->setLastname($lastname);
        $user->setAddress('Admin Address');
        $user->setZipcode('00000');
        $user->setCity('Admin City');
        
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success('Admin user created successfully!');
        $io->info(sprintf('Email: %s', $email));

        return Command::SUCCESS;
    }
}
