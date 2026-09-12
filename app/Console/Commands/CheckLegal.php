<?php

namespace App\Console\Commands;

use App\Support\Legal;
use Illuminate\Console\Command;
use Throwable;

class CheckLegal extends Command
{
    protected $signature = 'legal:check {--published : Require current published versions before production use}';

    protected $description = 'Verify every retained legal document and its publication readiness';

    public function handle(Legal $legal): int
    {
        try {
            foreach ($legal->catalog() as $slug => $entry) {
                foreach (array_keys($entry['versions']) as $version) {
                    $legal->document($slug, $version);
                }
            }

            if ($this->option('published') && ! $legal->isPublished()) {
                $this->error('Legal documents are drafts. Complete the details and publish new versions before production use.');

                return self::FAILURE;
            }

            $this->info('Legal document integrity verified.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
