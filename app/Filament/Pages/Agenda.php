<?php

namespace App\Filament\Pages;

use App\Models\Project;
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
use Illuminate\Support\Collection;

class Agenda extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon =  'heroicon-o-view-boards'; // 'heroicon-o-calendar-days';

    protected static string $view = 'filament.pages.agenda';

    protected static ?string $slug = 'agenda';

    protected static ?int $navigationSort = 6;

    protected static function getNavigationLabel(): string
    {
        return __('Agenda');
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Management');
    }

    public string $month;

    public array $days = [];

    public array $rows = [];

    public function mount(): void
    {
        $this->month = now()->format('Y/m');
        $this->buildAgenda();
        $this->form->fill([
            'month' => $this->month,
        ]);
    }

    protected function getHeading(): string|Htmlable
    {
        return __('Agenda');
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

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View agenda') ?? false;
    }

    protected static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('View agenda') ?? false;
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
                                ->rules(['regex:/^\d{4}\/\d{2}$/']) // força formato 9999/99
                                ->default(fn() => $this->month)
                                ->required(),


                        ]),
                ]),
        ];
    }

    private function buildAgenda(): void
    {
        [$startOfMonth, $endOfMonth] = $this->monthBoundaries($this->month);
        $this->days = $this->generateDays($startOfMonth, $endOfMonth);

        $sprintQuery = Sprint::query()
            ->with(['project.owner.roles', 'project.users.roles'])
            ->whereDate('starts_at', '<=', $endOfMonth->toDateString())
            ->whereDate('ends_at', '>=', $startOfMonth->toDateString());

        if (!auth()->user()->hasRole('Administrator')) {
            $sprintQuery->whereHas('project', function ($query) {
                $query->where('owner_id', auth()->user()->id)
                    ->orWhereHas('users', function ($query) {
                        return $query->where('users.id', auth()->user()->id);
                    });
            });
        }

        $sprints = $sprintQuery->get();

        // Scrum: aggregate logged hours per user/day/sprint
        $scrumHoursQuery = TicketHour::query()
            ->with(['user.roles', 'ticket.sprint.project'])
            ->whereBetween('created_at', [
                $startOfMonth->copy()->startOfDay(),
                $endOfMonth->copy()->endOfDay()
            ])
            ->whereHas('ticket', function ($query) {
                $query->whereNotNull('sprint_id');
            })
            ->whereHas('ticket.sprint');

        if (!auth()->user()->hasRole('Administrator')) {
            $scrumHoursQuery->whereHas('ticket.project', function ($query) {
                $query->where('owner_id', auth()->user()->id)
                    ->orWhereHas('users', function ($query) {
                        return $query->where('users.id', auth()->user()->id);
                    });
            });
        }

        $scrumHours = $scrumHoursQuery->get();
        $allowedRoles = ['Desenvolvedor', 'Consultor'];
        $scrumAggregate = [];
        foreach ($scrumHours as $hour) {
            $user = $hour->user;
            $ticket = $hour->ticket;
            $sprint = $ticket?->sprint;
            if (!$user || !$ticket || !$sprint) {
                continue;
            }
            if (!(method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles))) {
                continue;
            }
            $dateKey = $hour->created_at->toDateString();
            $userId = $user->id;
            $sprintId = $sprint->id;
            if (!isset($scrumAggregate[$userId])) {
                $scrumAggregate[$userId] = [];
            }
            if (!isset($scrumAggregate[$userId][$dateKey])) {
                $scrumAggregate[$userId][$dateKey] = [];
            }
            if (!isset($scrumAggregate[$userId][$dateKey][$sprintId])) {
                $scrumAggregate[$userId][$dateKey][$sprintId] = 0.0;
            }
            $scrumAggregate[$userId][$dateKey][$sprintId] += (float) $hour->value;
        }

        $userIdToUser = collect();
        $rows = [];

        foreach ($sprints as $sprint) {
            $project = $sprint->project;
            if (!$project) {
                continue;
            }

            $contributors = $project->contributors ?? collect();
            if (!($contributors instanceof Collection)) {
                $contributors = collect($contributors);
            }
            if ($project->owner) {
                $contributors->push($project->owner);
            }
            $contributors = $contributors->unique('id');

            // Only keep users with roles "Desenvolvedor" or "Consultor"
            $allowedRoles = ['Desenvolvedor', 'Consultor'];
            $contributors = $contributors->filter(function ($user) use ($allowedRoles) {
                return $user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles);
            });

            $from = Carbon::parse(max($startOfMonth->toDateString(), $sprint->starts_at?->toDateString()));
            $to = Carbon::parse(min($endOfMonth->toDateString(), $sprint->ends_at?->toDateString()));

            foreach ($contributors as $user) {
                if (!$user) {
                    continue;
                }
                $userId = $user->id;
                if (!isset($rows[$userId])) {
                    $rows[$userId] = [
                        'user'   => $user,
                        'name' => (String)$user->name, // já como string
                        'days'      => array_fill_keys(array_keys($this->days), []),
                    ];
                }

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
                $userIdToUser->put($userId, $user);
            }
        }

        // Kanban: add time logs by day and ticket
        $kanbanHoursQuery = TicketHour::query()
            ->with(['user.roles', 'ticket.project'])
            ->whereBetween('created_at', [
                $startOfMonth->copy()->startOfDay(),
                $endOfMonth->copy()->endOfDay()
            ])
            ->whereHas('ticket.project', function ($query) {
                $query->where('type', 'kanban');
            });

        if (!auth()->user()->hasRole('Administrator')) {
            $kanbanHoursQuery->whereHas('ticket.project', function ($query) {
                $query->where('owner_id', auth()->user()->id)
                    ->orWhereHas('users', function ($query) {
                        return $query->where('users.id', auth()->user()->id);
                    });
            });
        }

        $kanbanHours = $kanbanHoursQuery->get();

        // Aggregate hours per user/day/ticket with distinct activity buckets
        $allowedRoles = ['Desenvolvedor', 'Consultor'];
        $aggregate = [];
        foreach ($kanbanHours as $hour) {
            $user = $hour->user;
            $ticket = $hour->ticket;
            $project = $ticket?->project;
            if (!$user || !$ticket || !$project) {
                continue;
            }
            if (!(method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles))) {
                continue;
            }

            $dateKey = $hour->created_at->toDateString();
            $userId = $user->id;
            $ticketId = $ticket->id;
            $activityId = $hour->activity_id ?? 0;

            if (!isset($aggregate[$userId])) {
                $aggregate[$userId] = [];
            }
            if (!isset($aggregate[$userId][$dateKey])) {
                $aggregate[$userId][$dateKey] = [];
            }
            if (!isset($aggregate[$userId][$dateKey][$ticketId])) {
                $aggregate[$userId][$dateKey][$ticketId] = [
                    'project' => $project->name,
                    'ticket' => $ticket->name,
                    'activities' => [],
                ];
            }
            if (!isset($aggregate[$userId][$dateKey][$ticketId]['activities'][$activityId])) {
                $aggregate[$userId][$dateKey][$ticketId]['activities'][$activityId] = 0.0;
            }
            $aggregate[$userId][$dateKey][$ticketId]['activities'][$activityId] += (float) $hour->value;
        }

        // Fill rows with kanban aggregated labels
        foreach ($aggregate as $userId => $dates) {
            $user = $kanbanHours->firstWhere('user.id', $userId)?->user;
            if (!isset($rows[$userId])) {
                $rows[$userId] = [
                    'user'   => $user,
                    'name'   => (string)($user?->name ?? ''),
                    'days'   => array_fill_keys(array_keys($this->days), []),
                ];
            }
            foreach ($dates as $dateKey => $tickets) {
                foreach ($tickets as $ticketId => $data) {
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

        $sorted = collect($rows)
            ->sortBy(fn($r) => mb_strtolower($r['name'] ?? ($r['user']->name ?? '')))
            ->values()
            ->all();

        $this->rows = $sorted;
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
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $days[$cursor->format('Y-m-d')] = $cursor->format('d');
            $cursor->addDay();
        }
        return $days;
    }
}
