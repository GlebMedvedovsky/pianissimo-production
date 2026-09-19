<?php
namespace Tests;
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    public function createApplication()
    {
        $app=require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if ($app['config']->get('database.connections.mysql.database')!=='pianissimo_test') throw new \RuntimeException('Tests require the isolated pianissimo_test database.');
        $app['config']->set('cms.export_prefix','test-exports');
        return $app;
    }
}
