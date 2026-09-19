<?php
namespace App\Filament\Resources;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\{EditAction,DeleteAction};
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
            ->recordActions([EditAction::make(),\Filament\Actions\Action::make('preview')->label('Datei ansehen')->url(fn($record)=>route('admin.media',$record))->openUrlInNewTab(),DeleteAction::make()])->defaultSort('alt');
    }
    public static function getPages(): array { return ['index'=>Pages\ListMediaAssets::route('/'),'create'=>Pages\CreateMediaAsset::route('/create'),'edit'=>Pages\EditMediaAsset::route('/{record}/edit')]; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return !\App\Models\Membership::where('cover_asset_id',$record->id)->orWhere('hover_asset_id',$record->id)->exists() && !\App\Models\ProjectMedia::where('media_asset_id',$record->id)->exists() && !\App\Models\Track::where('audio_asset_id',$record->id)->exists() && !\App\Models\Setting::where('data','like','%'.$record->id.'%')->exists() && !\App\Models\Project::where('seo','like','%'.$record->id.'%')->exists(); }
}
