@php
    $days = $this->days;
    $rows = $this->rows;
@endphp

<x-filament::page>
    <div class="space-y-6">
        {{ $this->form }}

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-700 sticky left-0 bg-white z-10">{{ __('User') }}</th>
                        @foreach($days as $dateKey => $day)
                            <th class="px-3 py-2 text-center font-medium text-gray-700">{{ $day }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($rows as $row)
                        <tr>
                            <td class="px-3 py-2 whitespace-nowrap sticky left-0 bg-white z-10">
                                {{ $row['name'] ?? (data_get($row, 'user.name') ?? '') }}
                            </td>
                            @foreach($days as $dateKey => $day)
                                <td class="px-2 py-2 align-top">
                                    @if(!empty($row['days'][$dateKey]))
                                        <div class="space-y-1">
                                            @foreach($row['days'][$dateKey] as $item)
                                                @php
                                                    $label = is_array($item) ? ($item['label'] ?? '') : (string) $item;
                                                    $type = is_array($item) ? ($item['type'] ?? 'scrum') : 'scrum';
                                                    $has = is_array($item) ? (bool) ($item['has_hours'] ?? false) : false;
                                                    $bg = $has ? ($type === 'kanban' ? '#3e9300' : '#3b82f6') : '#9ca3af';
                                                @endphp
                                                <div class="px-2 py-1 rounded flex items-center justify-center text-center text-xs text-white" style="background-color: {{ $bg }};">
                                                    <div class="text-xs leading-4">{{ $label }}</div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($days) + 1 }}" class="px-3 py-6 text-center text-gray-500">
                                {{ __('No data for the selected month.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament::page>


