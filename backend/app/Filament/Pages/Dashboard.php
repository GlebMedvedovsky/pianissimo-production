<?php
namespace App\Filament\Pages;
class Dashboard extends \Filament\Pages\Page
{
    protected static ?string $title='Dashboard';
    protected static ?int $navigationSort=-10;
    protected static ?string $slug='';
    protected string $view='cms.dashboard';
    public array $owner=[];
    public function mount(): void { $this->owner=app(\App\Services\OwnerDashboard::class)->data(); }
}
