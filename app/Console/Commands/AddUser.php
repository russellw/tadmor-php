<?php

namespace App\Console\Commands;

use App\Database\SharedSchema;
use App\Errors\ApiError;
use App\Services\Users;
use Illuminate\Console\Command;

class AddUser extends Command
{
    protected $signature = 'tadmor:adduser
        {--email= : login email}
        {--name= : full name}
        {--not-admin : create an ordinary user}';

    protected $description = 'Create (or reset) an administrator, reading the password from stdin. '
        .'Applies pending migrations first, so it can bootstrap an empty database.';

    public function handle(): int
    {
        SharedSchema::migrate();
        $password = rtrim((string) fgets(STDIN), "\r\n");
        try {
            Users::addOrReset((string) $this->option('email'), (string) $this->option('name'), $password,
                isAdmin: ! $this->option('not-admin'));
        } catch (ApiError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line("user {$this->option('email')} is ready");

        return self::SUCCESS;
    }
}
