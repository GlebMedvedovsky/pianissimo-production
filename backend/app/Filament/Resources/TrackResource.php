<?php
namespace App\Filament\Resources;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\{EditAction,DeleteAction};
use App\Filament\Fields;
class TrackResource extends Resource
{
    protected static ?int $navigationSort=20;
    protected static ?string $model = \App\Models\Track::class;
    protected static ?string $modelLabel = 'Track';
    protected static ?string $pluralModelLabel = 'Tracks';
    protected static string|\UnitEnum|null $navigationGroup = 'Inhalte';
    protected static ?string $recordTitleAttribute = 'title';
    public static function form(Schema $schema): Schema { return $schema->components(Fields::track())->columns(2); }
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->label('Track')->searchable()->sortable()->wrap(),TextColumn::make('id')->label('ID')->searchable(),TextColumn::make('updated_at')->label('Geändert')->dateTime('d.m.Y H:i')->sortable()])
            ->recordActions([EditAction::make(),DeleteAction::make()])->defaultSort('title');
    }
    public static function getPages(): array { return ['index'=>Pages\ListTracks::route('/'),'create'=>Pages\CreateTrack::route('/create'),'edit'=>Pages\EditTrack::route('/{record}/edit')]; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return !\App\Models\ProjectTrack::where('track_id',$record->id)->exists(); }
}
