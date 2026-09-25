<?php
namespace App\Filament\Resources;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use App\Filament\Fields;
class MediaAssetResource extends Resource
{
    protected static ?int $navigationSort=30;
    protected static ?string $model = \App\Models\MediaAsset::class;
    protected static ?string $modelLabel = 'Medium';
    protected static ?string $pluralModelLabel = 'Medien';
    protected static string|\UnitEnum|null $navigationGroup = 'Inhalte';
    protected static ?string $recordTitleAttribute = 'alt';
    public static function form(Schema $schema): Schema { return $schema->components(Fields::asset())->columns(2); }
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('alt')->label('Medium')->searchable()->sortable()->wrap(),TextColumn::make('id')->label('ID')->searchable(),TextColumn::make('updated_at')->label('Geändert')->dateTime('d.m.Y H:i')->sortable()])
            ->recordActions([EditAction::make(),\Filament\Actions\Action::make('preview')->label('Datei ansehen')->url(fn($record)=>route('admin.media',$record))->openUrlInNewTab(),
                \Filament\Actions\Action::make('usages')->label('Verwendungen anzeigen')->icon('heroicon-o-link')->modalHeading('Verwendungen')->modalSubmitAction(false)->modalCancelActionLabel('Schließen')->modalContent(fn($record)=>view('cms.media-usages',['usages'=>app(\App\Services\MediaUsageResolver::class)->usages($record)])),
                \Filament\Actions\Action::make('delete')->label('Datei löschen')->color('danger')->icon('heroicon-o-trash')->requiresConfirmation()->modalHeading('Datei löschen oder Verwendungen ändern')->modalContent(fn($record)=>view('cms.media-usages',['usages'=>app(\App\Services\MediaUsageResolver::class)->usages($record)]))->schema(fn($record)=>app(\App\Services\MediaUsageResolver::class)->usages($record)?[
                    \Filament\Forms\Components\CheckboxList::make('usages')->label('Verwendungen auswählen')->options(collect(app(\App\Services\MediaUsageResolver::class)->usages($record))->mapWithKeys(fn($u)=>[$u['key']=>$u['location'].' → '.$u['slot'].($u['required']?' · Ersatz erforderlich':'')])->all())->required(),
                    \Filament\Forms\Components\Select::make('operation')->label('Aktion')->options(['detach'=>'Verwendung entfernen','replace'=>'Überall ersetzen','replace-delete'=>'Alle Verwendungen ersetzen und Datei löschen'])->required()->live(),
                    \Filament\Forms\Components\Select::make('replacement')->label('Ersatzdatei')->options(fn()=>\App\Models\MediaAsset::whereKeyNot($record->id)->orderBy('alt')->get()->mapWithKeys(fn($a)=>[$a->id=>($a->alt?:$a->id).' · '.$a->type]))->searchable()->required(fn(\Filament\Schemas\Components\Utilities\Get $get)=>in_array($get('operation'),['replace','replace-delete'],true))->visible(fn(\Filament\Schemas\Components\Utilities\Get $get)=>in_array($get('operation'),['replace','replace-delete'],true)),
                ]:[])->action(function($record,array $data): void {
                    $service=app(\App\Services\MediaUsageResolver::class); $usages=$service->usages($record);
                    if (!$usages) $service->deleteUnused($record);
                    elseif (($data['operation']??null)==='detach') $service->detach($record,$data['usages']??[]);
                    else $service->replace($record,\App\Models\MediaAsset::findOrFail($data['replacement']),$data['usages']??[],($data['operation']??null)==='replace-delete');
                    \Filament\Notifications\Notification::make()->title('Medienaktion ausgeführt')->success()->send();
                }),
            ])->defaultSort('alt');
    }
    public static function getPages(): array { return ['index'=>Pages\ListMediaAssets::route('/'),'create'=>Pages\CreateMediaAsset::route('/create'),'edit'=>Pages\EditMediaAsset::route('/{record}/edit')]; }
}
