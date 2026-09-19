<?php
namespace App\Filament\Resources;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\{EditAction,DeleteAction};
use App\Filament\Fields;
class CvEntryResource extends Resource
{
    protected static ?int $navigationSort=50;
    protected static ?string $model = \App\Models\CvEntry::class;
    protected static ?string $modelLabel = 'CV-Eintrag';
    protected static ?string $pluralModelLabel = 'CV / Lebenslauf';
    protected static string|\UnitEnum|null $navigationGroup = 'Inhalte';
    protected static ?string $recordTitleAttribute = 'title';
    public static function form(Schema $schema): Schema { return $schema->components(Fields::cv())->columns(2); }
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->label('CV-Eintrag')->searchable()->sortable()->wrap(),TextColumn::make('id')->label('ID')->searchable(),TextColumn::make('updated_at')->label('Geändert')->dateTime('d.m.Y H:i')->sortable()])
            ->recordActions([EditAction::make(),DeleteAction::make()])->defaultSort('title');
    }
    public static function getPages(): array { return ['index'=>Pages\ListCvEntrys::route('/'),'create'=>Pages\CreateCvEntry::route('/create'),'edit'=>Pages\EditCvEntry::route('/{record}/edit')]; }
}
