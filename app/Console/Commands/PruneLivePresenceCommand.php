<?php

namespace App\Console\Commands;

use App\CentralLogics\LivePresenceService;
use Illuminate\Console\Command;

class PruneLivePresenceCommand extends Command
{
    protected $signature = 'live-presence:prune {--hours=24 : Delete rows older than this many hours}';

    protected $description = 'Remove stale live_presence rows (default: older than 24 hours).';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $deleted = LivePresenceService::pruneStale($hours);

        $this->info(sprintf('Pruned %d live_presence row(s) older than %d hour(s).', $deleted, $hours));

        return self::SUCCESS;
    }
}
