<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SkillResource\Pages;
use App\Filament\Resources\SkillResource\RelationManagers;
use App\Models\Skill;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Illuminate\Support\Str;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SkillResource extends Resource
{
    protected static ?string $model = Skill::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = 2;

    protected static function getNavigationGroup(): ?string
    {
        return __('Permissions');
    }

    protected static function getNavigationLabel(): string
    {
        return __('Skills');
    }

    public static function getModelLabel(): string
    {
        return __('Skill');
    }

    public static function getPluralLabel(): ?string
    {
        return static::getNavigationLabel();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->reactive() // <-- v2
                    ->afterStateUpdated(function (string $context = null, \Closure $set, $state = null) {
                        if ($context !== 'create') {
                            return;
                        }
                        $set('slug', Str::slug($state));
                    }),

                Forms\Components\TextInput::make('slug')
                    ->label(__('Slug'))
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->rules(['alpha_dash'])
                    ->helperText(__('Will be auto-generated from name if left empty')),

                Forms\Components\Textarea::make('description')
                    ->label(__('Description'))
                    ->maxLength(1000)
                    ->rows(3),

                Forms\Components\ColorPicker::make('color')
                    ->label(__('Color'))
                    ->default('#3B82F6')
                    ->required(),

                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->label(__('Active')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color')
                    ->label(__('Color')),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('users_count')
                    ->counts('users')
                    ->label(__('Users Count'))
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label(__('Active')),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->trueLabel(__('Active only'))
                    ->falseLabel(__('Inactive only')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading(__('Delete Skill'))
                    ->modalSubheading(__('Are you sure you want to delete this skill? This action cannot be undone.'))
                    ->modalButton(__('Yes, delete it'))
                    ->before(function (Skill $record) {
                        if (!$record->canBeDeleted()) {
                            \Filament\Notifications\Notification::make()
                                ->title(__('Cannot delete skill'))
                                ->body(__('This skill cannot be deleted because it is assigned to one or more users.'))
                                ->danger()
                                ->send();
                            return false;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->before(function ($records) {
                        $cannotDelete = $records->filter(fn($record) => !$record->canBeDeleted());
                        if ($cannotDelete->count() > 0) {
                            \Filament\Notifications\Notification::make()
                                ->title(__('Cannot delete some skills'))
                                ->body(__('Some skills cannot be deleted because they are assigned to users.'))
                                ->danger()
                                ->send();
                            return false;
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSkills::route('/'),
            'create' => Pages\CreateSkill::route('/create'),
            'edit' => Pages\EditSkill::route('/{record}/edit'),
        ];
    }
}
