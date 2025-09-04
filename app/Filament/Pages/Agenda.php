<?php

namespace App\Filament\Pages;

use App\Models\Project;
use App\Models\Sprint;
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
                        $rows[$userId]['days'][$key][] = $project->name . ' - ' . $sprint->name;
                    }
                    $cursor->addDay();
                }
                $userIdToUser->put($userId, $user);
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
