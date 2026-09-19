<?php
namespace App\Filament\Resources;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\{EditAction,DeleteAction};
use App\Filament\Fields;
class ProjectResource extends Resource
{
    protected static ?int $navigationSort=10;
    protected static ?string $model = \App\Models\Project::class;
    protected static ?string $modelLabel = 'Projekt';
    protected static ?string $pluralModelLabel = 'Projekte';
    protected static string|\UnitEnum|null $navigationGroup = 'Inhalte';
    protected static ?string $recordTitleAttribute = 'title';
    public static function form(Schema $schema): Schema { return $schema->components(Fields::project())->columns(2); }
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->label('Projekt')->searchable()->sortable()->wrap(),TextColumn::make('id')->label('ID')->searchable(),TextColumn::make('updated_at')->label('Geändert')->dateTime('d.m.Y H:i')->sortable()])
            ->recordActions([EditAction::make(),DeleteAction::make()])->defaultSort('title');
    }
    public static function getPages(): array { return ['index'=>Pages\ListProjects::route('/'),'create'=>Pages\CreateProject::route('/create'),'edit'=>Pages\EditProject::route('/{record}/edit')]; }
}
