<?php

namespace App\Exports;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketHour;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;


class TimesheetExport implements FromCollection, WithHeadings, WithCustomCsvSettings
{
    protected array $params;

    public function __construct(array $params)
    {
        $this->params = $params;
    }

    public function headings(): array
    {
        return [
            '#',
            'Project',
            'Ticket',
            'Details',
            'User',
            'Time',
            'Hours',
            'Activity',
            'Date',
        ];
    }

    /**
     * @return Collection
     */
    public function collection(): Collection
    {
        $collection = collect();
        $totalHours = 0;

        $hours = TicketHour::where('user_id', $this->params['user'])
            ->whereBetween('execution_at', [$this->params['start_date'], $this->params['end_date']])
            ->get();

        foreach ($hours as $item) {
            $totalHours += $item->value;

            $collection->push([
                '#' => $item->ticket->code,
                'project' => $item->ticket->project->name,
                'ticket' => $item->ticket->name,
                'details' => $item->comment,
                'user' => $item->user->name,
                'time' => $item->forHumans,
                'hours' => $this->formatDecimalHoursToTime($item->value),
                'activity' => $item->activity ? $item->activity->name : '-',
                'date' => $item->execution_at->format(__('Y-m-d g:i A')),
            ]);
        }

        $collection->push([
            '#' => '',
            'project' => '',
            'ticket' => '',
            'details' => '',
            'user' => '',
            'time' => 'TOTAL',
            'hours' => $this->formatDecimalHoursToTime($totalHours),
            'activity' => '',
            'date' => '',
        ]);

        return $collection;
    }

    public function getCsvSettings(): array
    {
        return [
            'input_encoding' => 'UTF-8',
            'output_encoding' => 'UTF-8',
            'use_bom' => true,
            'delimiter' => ';',
        ];
    }

    private function formatDecimalHoursToTime($decimalHours): string
    {
        $hours = floor($decimalHours);
        $minutes = ($decimalHours - $hours) * 60;

        return sprintf('%02d:%02d', $hours, $minutes);
    }
}
