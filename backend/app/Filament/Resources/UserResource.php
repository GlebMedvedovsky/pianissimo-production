<?php
namespace App\Filament\Resources;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\{EditAction,DeleteAction};
use App\Filament\Fields;
class UserResource extends Resource
{
    protected static ?int $navigationSort=20;
    protected static ?string $model = \App\Models\User::class;
    protected static ?string $modelLabel = 'Benutzer';
    protected static ?string $pluralModelLabel = 'Benutzer';
    protected static string|\UnitEnum|null $navigationGroup = 'System';
    protected static ?string $recordTitleAttribute = 'name';
    public static function form(Schema $schema): Schema { return $schema->components(Fields::user())->columns(2); }
    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Benutzer')->searchable()->sortable()->wrap(),TextColumn::make('id')->label('ID')->searchable(),TextColumn::make('updated_at')->label('Geändert')->dateTime('d.m.Y H:i')->sortable()])
            ->recordActions([EditAction::make(),DeleteAction::make()])->defaultSort('name');
    }
    public static function getPages(): array { return ['index'=>Pages\ListUsers::route('/'),'create'=>Pages\CreateUser::route('/create'),'edit'=>Pages\EditUser::route('/{record}/edit')]; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return $record->id!==auth()->id() && \App\Models\User::count()>1; }
}
