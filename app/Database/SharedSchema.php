<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The migration runner for the shared schema (spec/README.md). Every
 * db/migrations/*.up.sql is applied in lexical order, each in its own
 * transaction together with the schema_migrations row that records it. The
 * files are tadmor's and are never edited here; Laravel's own migration
 * system is not used for them.
 */
class SharedSchema
{
    /** Serialises concurrent runners. */
    private const LOCK_KEY = 0x7AD00001;

    /** @return list<string> the versions applied by this call */
    public static function migrate(): array
    {
        $files = glob(base_path('db/migrations/*.up.sql'));
        if (! $files) {
            throw new RuntimeException('no *.up.sql migration files found in db/migrations');
        }
        sort($files, SORT_STRING);

        $applied = [];
        DB::select('SELECT pg_advisory_lock(?)', [self::LOCK_KEY]);
        try {
            DB::statement('CREATE TABLE IF NOT EXISTS schema_migrations ('
                .' version text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())');
            $done = array_flip(DB::table('schema_migrations')->pluck('version')->all());
            foreach ($files as $file) {
                $version = basename($file, '.up.sql');
                if (isset($done[$version])) {
                    continue;
                }
                DB::transaction(function () use ($file, $version) {
                    // Unprepared, so one call can run the file's many statements.
                    DB::unprepared(file_get_contents($file));
                    DB::table('schema_migrations')->insert(['version' => $version]);
                });
                $applied[] = $version;
            }
        } finally {
            DB::select('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
        }

        return $applied;
    }
}
