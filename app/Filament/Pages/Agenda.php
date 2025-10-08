<?php

namespace App\Filament\Pages;

use App\Models\Project;
use App\Models\Role; 
use App\Models\Sprint;
use App\Models\TicketHour;
use App\Models\User; 
use Carbon\Carbon;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder; 
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class Agenda extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static string $view = 'filament.pages.agenda';

    protected static ?string $slug = 'agenda';

    protected static ?int $navigationSort = 5;

    public string $month;

    public array $days = [];

    public array $rows = [];

    protected static function getNavigationLabel(): string
    {
        return __('Agenda');
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Management');
    }

    protected function getHeading(): string|Htmlable
    {
        return __('Agenda');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('View agenda') ?? false;
    }

    protected static function shouldRegisterNavigation(): bool
    {
        return static::canAccess(); 
    }

    protected function getFormSchema(): array
    {
        return [
            Card::make()
                ->schema([
                    Grid::make()
                        ->columns(3)
                        ->schema([
                            TextInput::make('month')
                                ->label(__('Month'))
                                ->helperText(__('Format: YYYY/MM'))
                                ->rules(['regex:/^\d{4}\/\d{2}$/']) 
                                ->default(fn() => $this->month)
                                ->required(),
                        ]),
                ]),
        ];
    }

    protected function getActions(): array
    {
        return [
            Action::make('search')
                ->label(__('Search'))
                ->button()
                ->action(function () {
                    $data = $this->form->getState();
                    $this->month = $data['month'];
                    $this->buildAgenda();
                }),
        ];
    }

    public function mount(): void
    {
        $this->month = now()->format('Y/m');
        $this->buildAgenda();
        $this->form->fill([
            'month' => $this->month,
        ]);
    }

    private function applyProjectScope(Builder $query, string $projectRelation = 'project'): Builder
    {
        $user = Auth::user();

        if (!$user || $user->hasRole('Administrator')) {
            return $query;
        }

        return $query->whereHas($projectRelation, function ($query) use ($user) {
            $query->where('owner_id', $user->id)
                ->orWhereHas('users', function ($query) use ($user) {
                    return $query->where('users.id', $user->id);
                });
        });
    }

    private function getAllowedRoleNames(): array
    {
        return Role::where('must_have_agenda', true)->pluck('name')->toArray();
    }

    private function getBaseTicketHourQuery(Carbon $startOfMonth, Carbon $endOfMonth, string $projectRelation = 'ticket.project'): Builder
    {
        $query = TicketHour::query()
            ->with(['user.roles', 'ticket.project'])
            ->whereBetween('execution_at', [
                $startOfMonth->copy()->startOfDay(),
                $endOfMonth->copy()->endOfDay()
            ]);

        return $this->applyProjectScope($query, $projectRelation);
    }

    private function buildAgenda(): void
    {
        [$startOfMonth, $endOfMonth] = $this->monthBoundaries($this->month);
        $this->days = $this->generateDays($startOfMonth, $endOfMonth);
        $allowedRoles = $this->getAllowedRoleNames();

        $sprintQuery = Sprint::query()
            ->with(['project.owner.roles', 'project.users.roles'])
            ->whereDate('starts_at', '<=', $endOfMonth->toDateString())
            ->whereDate('ends_at', '>=', $startOfMonth->toDateString());

        $sprints = $this->applyProjectScope($sprintQuery)->get();

        $scrumHoursQuery = $this->getBaseTicketHourQuery($startOfMonth, $endOfMonth)
            ->whereHas('ticket', fn ($query) => $query->whereNotNull('sprint_id'))
            ->whereHas('ticket.sprint');

        $scrumHours = $scrumHoursQuery->get();
        $scrumAggregate = $this->aggregateScrumHours($scrumHours, $allowedRoles);

        $kanbanHoursQuery = $this->getBaseTicketHourQuery($startOfMonth, $endOfMonth)
            ->whereHas('ticket.project', fn ($query) => $query->where('type', 'kanban'));

        $kanbanHours = $kanbanHoursQuery->get();
        $kanbanAggregate = $this->aggregateKanbanHours($kanbanHours, $allowedRoles);

        $rows = [];
        $this->processSprintsToRows($sprints, $startOfMonth, $endOfMonth, $scrumAggregate, $allowedRoles, $rows);
        $this->processKanbanToRows($kanbanAggregate, $kanbanHours, $rows);

        // 5. Ordenar e Atribuir
        $this->rows = collect($rows)
            ->sortBy(fn ($r) => mb_strtolower($r['name'] ?? ($r['user']->name ?? '')))
            ->values()
            ->all();
    }

    private function aggregateScrumHours(Collection $hours, array $allowedRoles): array
    {
        $aggregate = [];
        foreach ($hours as $hour) {
            $user = $hour->user;
            $ticket = $hour->ticket;
            $sprint = $ticket?->sprint;

            if (!$user || !$ticket || !$sprint || !$this->userHasAllowedRole($user, $allowedRoles)) {
                continue;
            }

            $dateKey = ($hour->execution_at ?? $hour->created_at)->toDateString();
            $userId = $user->id;
            $sprintId = $sprint->id;

            $aggregate[$userId][$dateKey][$sprintId] = ($aggregate[$userId][$dateKey][$sprintId] ?? 0.0) + (float)$hour->value;
        }
        return $aggregate;
    }

    private function aggregateKanbanHours(Collection $hours, array $allowedRoles): array
    {
        $aggregate = [];
        foreach ($hours as $hour) {
            $user = $hour->user;
            $ticket = $hour->ticket;
            $project = $ticket?->project;

            if (!$user || !$ticket || !$project || !$this->userHasAllowedRole($user, $allowedRoles)) {
                continue;
            }

            $dateKey = ($hour->execution_at ?? $hour->created_at)->toDateString();
            $userId = $user->id;
            $ticketId = $ticket->id;
            $activityId = $hour->activity_id ?? 0;

            if (!isset($aggregate[$userId][$dateKey][$ticketId])) {
                $aggregate[$userId][$dateKey][$ticketId] = [
                    'project' => $project->name,
                    'ticket' => $ticket->name,
                    'activities' => [],
                ];
            }
            $aggregate[$userId][$dateKey][$ticketId]['activities'][$activityId] =
                ($aggregate[$userId][$dateKey][$ticketId]['activities'][$activityId] ?? 0.0) + (float)$hour->value;
        }
        return $aggregate;
    }

    private function processSprintsToRows(Collection $sprints, Carbon $startOfMonth, Carbon $endOfMonth, array $scrumAggregate, array $allowedRoles, array &$rows): void
    {
        foreach ($sprints as $sprint) {
            $project = $sprint->project;
            if (!$project) {
                continue;
            }

            $contributors = collect($project->users ?? [])
                ->when($project->owner, fn ($c) => $c->push($project->owner))
                ->unique('id')
                ->filter(fn ($user) => $this->userHasAllowedRole($user, $allowedRoles));

            if ($contributors->isEmpty()) {
                continue;
            }
            
            $from = Carbon::parse(max($startOfMonth->toDateString(), $sprint->starts_at?->toDateString()));
            $to = Carbon::parse(min($endOfMonth->toDateString(), $sprint->ends_at?->toDateString()));

            foreach ($contributors as $user) {
                if (!$user) continue;

                $userId = $user->id;
                $this->initializeRow($rows, $userId, $user);

                $cursor = $from->copy();
                while ($cursor->lte($to)) {
                    $key = $cursor->format('Y-m-d');
                    if (isset($rows[$userId]['days'][$key])) {
                        $hours = (float) ($scrumAggregate[$userId][$key][$sprint->id] ?? 0);
                        $label = $project->name . ' - ' . $sprint->name . ($hours > 0 ? (' - ' . $hours . 'h') : '');
                        $rows[$userId]['days'][$key][] = [
                            'label' => $label,
                            'type' => 'scrum',
                            'has_hours' => $hours > 0,
                        ];
                    }
                    $cursor->addDay();
                }
            }
        }
    }

    private function processKanbanToRows(array $kanbanAggregate, Collection $kanbanHours, array &$rows): void
    {
        foreach ($kanbanAggregate as $userId => $dates) {
            $user = $kanbanHours->firstWhere('user.id', $userId)?->user;
            
            $this->initializeRow($rows, $userId, $user);

            foreach ($dates as $dateKey => $tickets) {
                foreach ($tickets as $data) {
                    $total = array_sum($data['activities']);
                    $label = $data['project'] . ' - ' . $data['ticket'] . ' - ' . $total . 'h';
                    
                    if (isset($rows[$userId]['days'][$dateKey])) {
                        $rows[$userId]['days'][$dateKey][] = [
                            'label' => $label,
                            'type' => 'kanban',
                            'has_hours' => $total > 0,
                        ];
                    }
                }
            }
        }
    }

    private function initializeRow(array &$rows, int $userId, ?User $user): void
    {
        if (!isset($rows[$userId])) {
            $rows[$userId] = [
                'user' => $user,
                'name' => (string)($user?->name ?? ''),
                'days' => array_fill_keys(array_keys($this->days), []),
            ];
        }
    }
    
    private function userHasAllowedRole(?User $user, array $allowedRoles): bool
    {
        return $user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles);
    }

    private function monthBoundaries(string $month): array
    {
        try {
            $dt = Carbon::createFromFormat('Y/m', $month)->startOfMonth();
        } catch (\Exception $e) {
            $dt = now()->startOfMonth();
        }
        $start = $dt->copy();
        $end = $dt->copy()->endOfMonth();
        return [$start, $end];
    }

    private function generateDays(Carbon $start, Carbon $end): array
    {
        $days = [];
        
        Carbon::setLocale('pt_BR');
        
        $cursor = $start->copy()->startOfWeek(0); // 0 = domingo
        
        if ($cursor->lt($start)) {
            $cursor = $start->copy();
        }
        
        while ($cursor->lte($end)) {
            $days[$cursor->format('Y-m-d')] = $cursor->format('d');
            $cursor->addDay();
        }
        return $days;
    }
}