<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class ResetDb extends Command
{
    /** Only throwaway databases may be wiped. */
    private const SUFFIXES = ['_conformance', '_test'];

    protected $signature = 'tadmor:resetdb';

    protected $description = 'Create the configured database if missing, then drop and recreate its public schema';

    public function handle(): int
    {
        $name = DB::connection()->getDatabaseName();
        if (! str_ends_with($name, self::SUFFIXES[0]) && ! str_ends_with($name, self::SUFFIXES[1])) {
            $this->error("refusing to wipe database \"$name\": its name must end in ".implode(' or ', self::SUFFIXES));

            return self::FAILURE;
        }

        // Connect to the maintenance database to create this one if needed.
        Config::set('database.connections.maintenance', array_merge(
            DB::connection()->getConfig(), ['url' => null, 'database' => 'postgres'],
        ));
        $admin = DB::connection('maintenance');
        if (! $admin->select('SELECT 1 FROM pg_database WHERE datname = ?', [$name])) {
            $admin->statement('CREATE DATABASE "'.str_replace('"', '""', $name).'"');
            $this->line("created database $name");
        }
        DB::purge('maintenance');

        DB::unprepared('SET client_min_messages = warning; DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        $this->line("wiped database $name");

        return self::SUCCESS;
    }
}
