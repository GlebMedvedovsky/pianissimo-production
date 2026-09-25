<?php
namespace App\Filament\Pages;
class Social extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=50;
    protected static string $settingsKey='social';
    protected static ?string $title='Social Links';
    protected static string|\UnitEnum|null $navigationGroup='Website';
    public function save(): void
    {
        $before=\App\Models\Setting::read('social')['links']??[];
        parent::save();
        $after=\App\Models\Setting::read('social')['links']??[];
        if ($before===$after) return;
        $key=fn(array $link)=>hash('sha256',json_encode(\Illuminate\Support\Arr::only($link,['provider','label','url']),JSON_THROW_ON_ERROR));
        $beforeIndex=collect($before)->mapWithKeys(fn($link)=>[$key($link)=>$link]);
        $afterIndex=collect($after)->mapWithKeys(fn($link)=>[$key($link)=>$link]);
        $audit=app(\App\Services\AuditLogger::class);
        $added=$afterIndex->diffKeys($beforeIndex); $removed=$beforeIndex->diffKeys($afterIndex);
        if($added->isNotEmpty())$audit->record('settings','social','add',null,['providers'=>$added->pluck('provider')->values()->all()]);
        if($removed->isNotEmpty())$audit->record('settings','social','remove',null,['providers'=>$removed->pluck('provider')->values()->all()]);
        $audit->record('settings','social','update',null,['beforeCount'=>count($before),'afterCount'=>count($after)]);
    }
}
