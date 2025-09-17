<?php

declare(strict_types=1);

namespace App\Http\Livewire\Timesheet;

use App\Models\Ticket;
use App\Models\TicketHour;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class TimeLogged extends Component implements HasTable
{
    use InteractsWithTable;

    public Ticket $ticket;

    protected function getFormModel(): Model|string|null
    {
        return $this->ticket;
    }

    protected function getTableQuery(): Builder
    {
        return $this->ticket->hours()->getQuery();
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('user.name')
                ->label(__('Owner'))
                ->sortable()
                ->formatStateUsing(fn($record) => view('components.user-avatar', ['user' => $record->user]))
                ->searchable(),

            Tables\Columns\TextColumn::make('value')
                ->label(__('Hours'))
                ->sortable()
                ->searchable(),
            Tables\Columns\TextColumn::make('comment')
                ->label(__('Comment'))
                ->limit(50)
                ->sortable()
                ->searchable(),

            Tables\Columns\TextColumn::make('activity.name')
                ->label(__('Activity'))
                ->sortable(),

            Tables\Columns\TextColumn::make('ticket.name')
                ->label(__('Ticket'))
                ->sortable(),

            Tables\Columns\TextColumn::make('created_at')
                ->label(__('Created at'))
                ->dateTime()
                ->sortable()
                ->searchable(),
            Tables\Columns\TextColumn::make('execution_at')
                ->label(__('Execution date'))
                ->date()
                ->sortable()
                ->searchable(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Tables\Actions\Action::make('delete')
                ->label(__('Delete'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Delete time record'))
                ->modalSubheading(__('Are you sure you want to delete this time record? This action cannot be undone.'))
                ->modalButton(__('Delete'))
                ->visible(fn (TicketHour $record): bool => 
                    auth()->user()->can('delete', $record) || 
                    $record->user_id === auth()->user()->id ||
                    $this->ticket->owner_id === auth()->user()->id ||
                    $this->ticket->responsible_id === auth()->user()->id
                )
                ->action(function (TicketHour $record): void {
                    $record->delete();
                    
                    Notification::make()
                        ->success()
                        ->title(__('Time record deleted'))
                        ->body(__('The time record has been successfully deleted.'))
                        ->send();
                }),
        ];
    }
}
