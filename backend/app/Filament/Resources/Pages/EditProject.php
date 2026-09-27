<?php
namespace App\Filament\Resources\Pages;
class EditProject extends \Filament\Resources\Pages\EditRecord
{
    protected static string $resource = \App\Filament\Resources\ProjectResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Show the form's configured noindex default without persisting a new
        // optional setting when the editor changes only an unrelated field.
        $data['seo']['noindex'] ??= true;
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['platform_links','external_links','seo'] as $field) {
            $original=$this->record->$field;
            $normalize=static function ($value) use ($field) {
                $value=array_filter($value??[],static fn($v)=>$v!==null && $v!=='');
                if($field==='seo') $value['noindex'] ??= true;
                ksort($value);
                return $value;
            };
            if($normalize($data[$field]??[])===$normalize($original)) $data[$field]=$original;
        }
        return $data;
    }

    protected function getSaveFormAction(): \Filament\Actions\Action { return parent::getSaveFormAction()->label('Entwurf speichern'); }

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\ActionGroup::make([
            \Filament\Actions\Action::make('creditsHelp')->label('Credits verstehen')->url(fn()=>\App\Filament\Pages\Help::getUrl(['article'=>'credits-bearbeiten']).'#credits-bearbeiten'),
            \Filament\Actions\Action::make('galleryHelp')->label('Galerie sortieren')->url(fn()=>\App\Filament\Pages\Help::getUrl(['article'=>'galerie-sortieren']).'#galerie-sortieren'),
            \Filament\Actions\Action::make('seoHelp')->label('Projekt-SEO verstehen')->url(fn()=>\App\Filament\Pages\Help::getUrl(['article'=>'seo-titel']).'#seo-titel'),
        ])->label('Hilfe')];
    }
}
