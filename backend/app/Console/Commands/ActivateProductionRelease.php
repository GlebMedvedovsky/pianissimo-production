<?php

namespace App\Console\Commands;

use App\Services\StaticReleaseActivator;
use Illuminate\Console\Command;
use Throwable;

class ActivateProductionRelease extends Command
{
    protected $signature = 'pianissimo:production:activate {publication} {sequence} {archive} {sha256} {--runner=}';
    protected $description = 'Validiert und aktiviert ein übertragenes statisches Pianissimo-Release';

    public function handle(StaticReleaseActivator $activator): int
    {
        if (! app()->environment('production') || config('cms.publication_driver') !== 'package') {
            $this->error('Aktivierung abgelehnt: Produktionsumgebung oder Paket-Treiber ist nicht konfiguriert.');
            return self::FAILURE;
        }
        try {
            $result = $activator->activate(
                (string) $this->argument('publication'),
                (string) $this->argument('sequence'),
                (string) $this->argument('archive'),
                (string) $this->argument('sha256'),
                (string) $this->option('runner'),
            );
            $this->info($result['result'] === 'already_active' ? 'Release ist bereits sicher aktiv.' : 'Release wurde sicher aktiviert.');
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Aktivierung fehlgeschlagen. Details stehen im geschützten Serverprotokoll.');
            return self::FAILURE;
        }
    }
}
