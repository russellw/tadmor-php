<?php

namespace App\Console\Commands;

use App\Database\SharedSchema;
use Illuminate\Console\Command;

class Migrate extends Command
{
    protected $signature = 'tadmor:migrate';

    protected $description = 'Apply pending shared-schema migrations from db/migrations';

    public function handle(): void
    {
        $applied = SharedSchema::migrate();
        foreach ($applied as $version) {
            $this->line("applied migration $version");
        }
        if (! $applied) {
            $this->line('schema is up to date');
        }
    }
}
