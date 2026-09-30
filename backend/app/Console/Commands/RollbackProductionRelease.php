<?php

namespace App\Console\Commands;

use App\Services\StaticReleaseActivator;
use Illuminate\Console\Command;
use Throwable;

class RollbackProductionRelease extends Command
{
    protected $signature = 'pianissimo:production:rollback {release-or-publication}';
    protected $description = 'Schaltet atomar auf ein validiertes, aufbewahrtes Pianissimo-Release zurück';

    public function handle(StaticReleaseActivator $activator): int
    {
        if (! app()->environment('production') || config('cms.publication_driver') !== 'package') {
            $this->error('Rollback abgelehnt: Produktionsumgebung oder Paket-Treiber ist nicht konfiguriert.');
            return self::FAILURE;
        }
        try {
            $result = $activator->rollback((string) $this->argument('release-or-publication'));
            $this->info($result['result'] === 'already_active' ? 'Gewähltes Release ist bereits sicher aktiv.' : 'Rollback wurde sicher aktiviert.');
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Rollback fehlgeschlagen. Details stehen im geschützten Serverprotokoll.');
            return self::FAILURE;
        }
    }
}
