<?php
namespace App\Filament\Resources\Pages;
class CreateMediaAsset extends \Filament\Resources\Pages\CreateRecord
{
    protected static string $resource = \App\Filament\Resources\MediaAssetResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!empty($data['storage_path'])) $data=array_merge($data,app(\App\Services\MediaStorage::class)->inspectStored($data['storage_path'],$data['id'],$data['original_name']??null));
        else $data=app(\App\Services\MediaStorage::class)->registerExisting($data);
        return $data;
    }
    protected function afterCreate(): void
    {
        app(\App\Services\AuditLogger::class)->record('media',$this->record->id,'upload',$this->record->sha256,['mime'=>$this->record->mime_type,'size'=>$this->record->file_size]);
    }

}
