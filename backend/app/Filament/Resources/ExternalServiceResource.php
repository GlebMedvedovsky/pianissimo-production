<?php
namespace App\Filament\Resources;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\{EditAction,DeleteAction};
use App\Filament\Fields;
class ExternalServiceResource extends Resource
{
    protected static ?int $navigationSort=40;
    protected static ?string $model = \App\Models\ExternalService::class;
    protected static ?string $modelLabel = 'Externer Dienst';
    protected static ?string $pluralModelLabel = 'Externe Dienste';
    protected static string|\UnitEnum|null $navigationGroup = 'Rechtliches & Datenschutz';
    protected static ?string $recordTitleAttribute = 'name';
    public static function form(Schema $schema): Schema { return $schema->components(Fields::externalService())->columns(2); }
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Externer Dienst')->searchable()->sortable()->wrap(),TextColumn::make('id')->label('ID')->searchable(),TextColumn::make('updated_at')->label('Geändert')->dateTime('d.m.Y H:i')->sortable()])
            ->recordActions([EditAction::make(),DeleteAction::make()])->defaultSort('name');
    }
    public static function getPages(): array { return ['index'=>Pages\ListExternalServices::route('/'),'create'=>Pages\CreateExternalService::route('/create'),'edit'=>Pages\EditExternalService::route('/{record}/edit')]; }
}
