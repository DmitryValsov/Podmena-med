<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ScheduleController extends Controller
{
    /**
     * Страница «Панель планирования» (шахматка).
     */
    public function index(Request $request)
    {
        // TODO: включить, когда будет isAdmin
        // abort_unless($request->user()?->isAdmin, 403);

        // Год / месяц из query (?year=2025&month=11) или текущие
        $year  = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month); // 1–12

        // Все пользователи (медперсонал)
        $users = User::with('department')
            ->orderBy('department_id')
            ->orderBy('name')
            ->get();

        // Массив staff под Vue
        $staff = $users->map(function (User $user) {
            return [
                'id'         => $user->id,
                'name'       => $user->name,
                'role'       => $user->job_id ?? 'Медсестра',
                'department' => $user->department?->name ?? 'Без отделения',
                'fte'        => 1.0,                                  // пока фиксируем ставку 1.0
                'baseNorm'   => (int) ($user->standart_hours ?? 168), // норма часов в месяц
            ];
        });

        // Смены за выбранный месяц
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = (clone $start)->endOfMonth();

        $shifts = Shift::whereBetween('date', [$start, $end])
            ->get()
            ->groupBy('user_id');

        // { [userId]: { [day]: {type,start,end,post} } }
        $shiftMatrix = [];

        foreach ($shifts as $userId => $items) {
            $shiftMatrix[$userId] = [];

            foreach ($items as $shift) {
                /** @var \App\Models\Shift $shift */
                $day = (int) $shift->date->format('j');

                $shiftMatrix[$userId][$day] = [
                    'type'  => $shift->type,                       // '12h' | '12n' | '24h' и т.д.
                    'start' => $shift->start_time?->format('H:i'), // '08:00'
                    'end'   => $shift->end_time?->format('H:i'),   // '20:00'
                    'post'  => $shift->post,                       // пост / место
                ];
            }
        }

        // Заявки на подмену для панели старшей
        // ВАЖНО: вместо FIELD() — CASE, чтобы работало в SQLite
        $swapRequests = ShiftSwap::with(['requester', 'responder', 'target'])
            ->orderByRaw("
                CASE status
                    WHEN 'await_head'       THEN 1
                    WHEN 'await_colleagues' THEN 2
                    WHEN 'approved'         THEN 3
                    WHEN 'declined'         THEN 4
                    ELSE 5
                END
            ")
            ->orderBy('date')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ShiftSwap $swap) {
                return [
                    'id'          => $swap->id,
                    'date'        => $swap->date?->toDateString(),
                    'dateLabel'   => $swap->date?->format('d.m.Y'),
                    'shift_type'  => $swap->shift_type, // '12h' / '24h'
                    'kindLabel'   => $swap->shift_type === '24h' ? '24 часа' : '12 часов',
                    'note'        => $swap->note,

                    'status'      => $swap->status,      // await_colleagues | await_head | approved | declined
                    'targetType'  => $swap->target_type, // all | direct

                    'requester'   => [
                        'id'   => $swap->requester?->id,
                        'name' => $swap->requester?->name,
                    ],
                    'responder'   => $swap->responder ? [
                        'id'   => $swap->responder->id,
                        'name' => $swap->responder->name,
                    ] : null,
                    'target'      => $swap->target ? [
                        'id'   => $swap->target->id,
                        'name' => $swap->target->name,
                    ] : null,

                    // пока head/approvedAt заглушки — можно добавить поля в БД позже
                    'head'        => null,
                    'approvedAt'  => null,
                ];
            });

        // Справочники для фильтров
        $departments = $users->pluck('department.name')->filter()->unique()->values();
        $roles       = $users->pluck('job_id')->filter()->unique()->values();

        // ВАЖНО: имя должно совпадать с resources/js/Pages/admin/Schedule.vue
        return Inertia::render('admin/Schedule', [
            'year'        => $year,
            'month'       => $month - 1,   // JS-месяц 0–11
            'staff'       => $staff,
            'shifts'      => $shiftMatrix,
            'departments' => $departments,
            'roles'       => $roles,
            'swaps'       => $swapRequests,
            'auth'        => [
                'user' => $request->user(),
            ],
        ]);
    }

    /**
     * Сохранение одной ячейки (одной смены).
     * Вызывается из модалки при нажатии «Сохранить».
     *
     * Поддерживает два варианта входа:
     *  - date = 'YYYY-MM-DD'
     *  - year, month, day
     */
    public function saveCell(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],

            // либо date, либо тройка year/month/day
            'date'  => ['nullable', 'date', 'required_without:year'],
            'year'  => ['nullable', 'integer', 'required_without:date'],
            'month' => ['nullable', 'integer', 'between:1,12', 'required_without:date'],
            'day'   => ['nullable', 'integer', 'min:1', 'max:31', 'required_without:date'],

            'type'  => ['nullable', 'string', 'max:50'],
            'start' => ['nullable', 'date_format:H:i'],
            'end'   => ['nullable', 'date_format:H:i'],
            'post'  => ['nullable', 'string', 'max:255'],
        ]);

        // Нормализуем дату в строку 'YYYY-MM-DD'
        if (!empty($data['date'])) {
            $date = Carbon::parse($data['date'])->toDateString();
        } else {
            $date = Carbon::create(
                $data['year'],
                $data['month'],
                $data['day']
            )->toDateString();
        }

        // Если type пустой — очищаем ячейку
        if (empty($data['type'])) {
            Shift::where('user_id', $data['user_id'])
                ->whereDate('date', $date)
                ->delete();

            return response()->noContent();
        }

        // Либо обновляем, либо создаём смену
        $shift = Shift::firstOrNew([
            'user_id' => $data['user_id'],
            'date'    => $date,
        ]);

        $shift->type       = $data['type'];
        $shift->start_time = $data['start'] ?? null;
        $shift->end_time   = $data['end'] ?? null;
        $shift->post       = $data['post'] ?? null;
        $shift->save();

        return response()->noContent();
    }

    /**
     * Очистить одну ячейку (из кнопки «Очистить» в модалке).
     */
    public function clearCell(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],

            'date'  => ['nullable', 'date', 'required_without:year'],
            'year'  => ['nullable', 'integer', 'required_without:date'],
            'month' => ['nullable', 'integer', 'between:1,12', 'required_without:date'],
            'day'   => ['nullable', 'integer', 'min:1', 'max:31', 'required_without:date'],
        ]);

        if (!empty($data['date'])) {
            $date = Carbon::parse($data['date'])->toDateString();
        } else {
            $date = Carbon::create(
                $data['year'],
                $data['month'],
                $data['day']
            )->toDateString();
        }

        Shift::where('user_id', $data['user_id'])
            ->whereDate('date', $date)
            ->delete();

        return response()->noContent();
    }

    /**
     * Очистить все смены за месяц (кнопка «Очистить месяц»).
     */
    public function clearMonth(Request $request)
    {
        $data = $request->validate([
            'year'  => ['required', 'integer'],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        $start = Carbon::create($data['year'], $data['month'], 1)->startOfDay();
        $end   = (clone $start)->endOfMonth();

        Shift::whereBetween('date', [$start, $end])->delete();

        return response()->noContent();
    }

    /**
     * Загрузить демо-данные (кнопка «Загрузить демо»).
     */
    public function seedDemo(Request $request)
    {
        $data = $request->validate([
            'year'  => ['required', 'integer'],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        $year  = $data['year'];
        $month = $data['month'];

        $start       = Carbon::create($year, $month, 1)->startOfDay();
        $end         = (clone $start)->endOfMonth();
        $daysInMonth = (int) $end->format('j');

        $users = User::with('department')
            ->orderBy('department_id')
            ->orderBy('id')
            ->get();

        \DB::transaction(function () use ($users, $year, $month, $start, $end, $daysInMonth) {
            // Сначала очищаем этот месяц
            Shift::whereBetween('date', [$start, $end])->delete();

            foreach ($users as $idx => $user) {
                $shiftToggle = $idx % 2 === 0 ? 'day' : 'night';

                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $date = Carbon::create($year, $month, $d);
                    $wd   = $date->dayOfWeekIso;    // 1–7 (пн–вс)
                    $weekIndex = intdiv($d - 1, 7); // 0..4

                    $workToday = ($weekIndex % 2 === 0)
                        ? ($wd >= 1 && $wd <= 5)   // 5-дневка
                        : ($wd >= 1 && $wd <= 6);  // 6-дневка

                    if (! $workToday) {
                        continue;
                    }

                    $type = $shiftToggle === 'day' ? '12h' : '12n';
                    $shiftToggle = $shiftToggle === 'day' ? 'night' : 'day';

                    Shift::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'date'    => $date->toDateString(),
                        ],
                        [
                            'type'       => $type,
                            'start_time' => $type === '12h' ? '08:00' : '20:00',
                            'end_time'   => $type === '12h' ? '20:00' : '08:00',
                            'post'       => 'пост',
                        ]
                    );
                }
            }
        });

        return response()->noContent();
    }

    /**
     * Старшая медсестра утверждает заявку на подмену.
     */
    public function approveSwap(Request $request, ShiftSwap $swap)
    {
        // TODO: включить проверку прав, когда появится isAdmin
        // abort_unless($request->user()?->isAdmin, 403);

        if ($swap->status !== 'await_head' && $swap->status !== 'await_colleagues') {
            return response()->json([
                'message' => 'Заявку в этом статусе утверждать нельзя',
            ], 422);
        }

        $swap->status = 'approved';
        $swap->save();

        return response()->noContent();
    }

    /**
     * Старшая медсестра отклоняет заявку на подмену.
     */
    public function rejectSwap(Request $request, ShiftSwap $swap)
    {
        // abort_unless($request->user()?->isAdmin, 403);

        $swap->status = 'declined';
        $swap->save();

        return response()->noContent();
    }
}
