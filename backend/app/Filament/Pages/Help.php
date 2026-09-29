<?php
namespace App\Filament\Pages;

use Livewire\Attributes\Url;

class Help extends \Filament\Pages\Page
{
    protected static ?string $title='Hilfe / Handbuch';
    protected static ?string $navigationLabel='Hilfe / Handbuch';
    protected static ?int $navigationSort=100;
    protected string $view='cms.help';
    #[Url] public string $search='';
    #[Url] public string $category='Alle';
    #[Url] public string $article='';
    public function categories(): array { return app(\App\Services\HelpCenter::class)->categories(); }
    public function articles(): array { return app(\App\Services\HelpCenter::class)->search($this->search,$this->category,$this->article); }
}
