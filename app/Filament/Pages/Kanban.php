<?php

namespace App\Filament\Pages;

use App\Helpers\KanbanScrumHelper;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\Project;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class Kanban extends Page implements HasForms
{
    use InteractsWithForms, KanbanScrumHelper;

    protected static ?string $navigationIcon = 'heroicon-o-view-boards';

    protected static ?string $slug = 'kanban/{project}';

    protected static string $view = 'filament.pages.kanban';

    protected static bool $shouldRegisterNavigation = false;

    protected $listeners = [
        'recordUpdated',
        'closeTicketDialog'
    ];

    public function mount(Project $project)
    {
        $this->project = $project;
        if ($this->project->type === 'scrum') {
            $this->redirect(route('filament.pages.scrum/{project}', ['project' => $project]));
        } elseif (
            $this->project->owner_id != auth()->user()->id
            &&
            !$this->project->users->where('id', auth()->user()->id)->count()
            &&
            !auth()->user()->hasRole('Administrator')
        ) {
            abort(403);
        }
        $this->form->fill();
    }

    protected function getActions(): array
    {
        return [
            Action::make('create-ticket')
                ->button()
                ->label(__('Create ticket'))
                ->color('primary')
                ->visible(fn() => auth()->user()->can('Create ticket'))
                ->form([
                    TextInput::make('name')
                        ->label(__('Ticket name'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    $defaultStatus = $this->project->status_type === 'custom'
                        ? TicketStatus::where('project_id', $this->project->id)->where('is_default', true)->first()?->id
                        : TicketStatus::whereNull('project_id')->where('is_default', true)->first()?->id;
                    $defaultType = TicketType::where('is_default', true)->first()?->id;
                    $defaultPriority = TicketPriority::where('is_default', true)->first()?->id;

                    Ticket::create([
                        'name' => $data['name'],
                        'content' => '',
                        'owner_id' => auth()->user()->id,
                        'responsible_id' => null,
                        'status_id' => $defaultStatus,
                        'project_id' => $this->project->id,
                        'type_id' => $defaultType,
                        'priority_id' => $defaultPriority,
                        'estimation' => null,
                        'epic_id' => null,
                        'sprint_id' => null,
                    ]);
                    $this->getRecords();
                    Filament::notify('success', __('Ticket created'));
                }),
            Action::make('refresh')
                ->button()
                ->label(__('Refresh'))
                ->color('secondary')
                ->action(function () {
                    $this->getRecords();
                    Filament::notify('success', __('Kanban board updated'));
                })
        ];
    }

    protected function getHeading(): string|Htmlable
    {
        return $this->kanbanHeading();
    }

    protected function getFormSchema(): array
    {
        return $this->formSchema();
    }

}
