<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Личный кабинет медсестры — страница расписания и подмен.
     * Vue-страница: resources/js/Pages/user/Schedule.vue
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Коллеги из того же отделения (если оно есть)
        $colleagues = User::query()
            ->when($user->department_id, function ($q) use ($user) {
                $q->where('department_id', $user->department_id);
            })
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get()
            ->map(function (User $u) {
                return [
                    'id'         => $u->id,
                    'name'       => $u->name,
                    'department' => $u->department?->name ?? 'Отделение',
                    'avatar'     => null,
                    'color'      => '#80D2F9',
                ];
            });

        /**
         * Входящие заявки для текущего пользователя:
         *  - личные, где он указан в target_user_id
         *  - общие из его отделения (кроме его собственных заявок)
         *  - не показываем отклонённые
         */
        $incoming = ShiftSwap::query()
            ->with(['requester', 'target', 'responder'])
            ->where('status', '!=', 'declined')
            ->where(function ($q) use ($user) {
                $q->where('target_user_id', $user->id)          // личные
                ->orWhere(function ($qq) use ($user) {        // общие от коллег
                    $qq->whereNull('target_user_id')
                        ->where('requester_id', '!=', $user->id);
                });
            })
            ->orderBy('date')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ShiftSwap $swap) use ($user) {
                // Входящие на фронт
                return [
                    'id'        => $swap->id,
                    'name'      => $swap->requester?->name ?? '—',
                    'avatar'    => null,
                    'type'      => $swap->target_user_id ? 'direct' : 'all',
                    'targetId'  => $swap->target_user_id,
                    'dateISO'   => $swap->date->toDateString(),
                    'time'      => '—', // сюда можно позже подставить время смены
                    'kindLabel' => $swap->shift_type === '24h' ? '24 часа' : '12 часов',
                    'note'      => $swap->note,
                    // кнопки на фронте работают с 'pending' | 'accepted'
                    'status'    => $swap->responder_id === $user->id ? 'accepted' : 'pending',
                    // взял ли уже кто-то эту подмену вообще
                    'taken'     => (bool) $swap->responder_id,
                    'busy'      => false,
                ];
            });

        /**
         * Мои заявки (которые я создал)
         */
        $mySwaps = ShiftSwap::query()
            ->with(['target', 'responder'])
            ->where('requester_id', $user->id)
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ShiftSwap $swap) {
                return [
                    'id'         => $swap->id,
                    'date'       => $swap->date->toDateString(),
                    'shift'      => $swap->shift_type,  // '12h' | '24h'
                    'note'       => $swap->note,
                    'targetType' => $swap->target_user_id ? 'direct' : 'all',
                    'targetUser' => $swap->target_user_id
                        ? [
                            'id'   => $swap->target_user_id,
                            'name' => $swap->target?->name,
                        ]
                        : null,
                    // 'await_colleagues' | 'await_head' | 'approved' | 'declined'
                    'status'     => $swap->status,
                    // кто согласился подменять (если уже есть)
                    'responder'  => $swap->responder
                        ? [
                            'id'   => $swap->responder->id,
                            'name' => $swap->responder->name,
                        ]
                        : null,
                    'offers'     => [], // если позже сделаем таблицу откликов
                ];
            });

        /**
         * Смены текущего пользователя за текущий месяц
         */
        $today = Carbon::today();
        $start = (clone $today)->startOfMonth();
        $end   = (clone $today)->endOfMonth();

        $shifts = Shift::where('user_id', $user->id)
            ->whereBetween('date', [$start, $end])
            ->orderBy('date')
            ->get()
            ->map(function (Shift $s) {
                return [
                    'id'    => $s->id,
                    'date'  => $s->date->toDateString(),       // '2025-11-14'
                    'type'  => $s->type,                        // '12h'/'24h'/'12n' и т.п.
                    'start' => $s->start_time?->format('H:i'),  // '08:00'
                    'end'   => $s->end_time?->format('H:i'),    // '20:00'
                    'post'  => $s->post,
                ];
            });

        return Inertia::render('user/Schedule', [
            'auth' => [
                'user' => [
                    'id'              => $user->id,
                    'name'            => $user->name,
                    'department_name' => $user->department?->name ?? null,
                    'avatar_url'      => null,
                ],
            ],
            'colleagues'       => $colleagues,
            'incomingRequests' => $incoming,
            'mySwaps'          => $mySwaps,
            'shifts'           => $shifts,
        ]);
    }

    /**
     * Создание заявки на подмену (форма "Быстрая подмена" + модалка).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'date'           => ['required', 'date'],
            'shift'          => ['required', 'string', 'max:20'],      // '12h' | '24h'
            'note'           => ['nullable', 'string', 'max:2000'],
            'target_type'    => ['required', 'in:all,direct'],
            'target_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($data['target_type'] === 'direct' && empty($data['target_user_id'])) {
            return back()->withErrors([
                'target_user_id' => 'Выберите адресата для личной заявки.',
            ]);
        }

        ShiftSwap::create([
            'requester_id'   => $user->id,
            'responder_id'   => null,
            'target_user_id' => $data['target_type'] === 'direct'
                ? $data['target_user_id']
                : null,
            'date'           => Carbon::parse($data['date']),
            'shift_type'     => $data['shift'],
            'note'           => $data['note'] ?? null,
            'target_type'    => $data['target_type'],        // all | direct
            'status'         => 'await_colleagues',
        ]);

        return redirect()
            ->route('user.schedule')
            ->with('success', 'Заявка на подмену создана.');
    }

    /**
     * Коллега соглашается подменить (accept).
     * Для общей заявки: первый откликнувшийся становится responder_id.
     * Для личной: только адресат может принять.
     */
    public function accept(Request $request, ShiftSwap $swap)
    {
        $user = $request->user();

        // Нельзя откликаться на свои же заявки
        if ($swap->requester_id === $user->id) {
            abort(403);
        }

        // Личная заявка — только адресат может принять
        if ($swap->target_user_id && $swap->target_user_id !== $user->id) {
            abort(403);
        }

        // Если уже есть кто-то, кто взял эту подмену — нельзя перехватывать
        if ($swap->responder_id && $swap->responder_id !== $user->id) {
            return back()->with('error', 'Подмена уже взята другим сотрудником.');
        }

        // Брать можно только пока заявка ищет коллег
        if ($swap->status !== 'await_colleagues') {
            return back()->with('error', 'Эта заявка уже не доступна для отклика.');
        }

        // Фиксируем, что именно этот пользователь подменяет, и ждём старшую
        $swap->responder_id = $user->id;
        $swap->status       = 'await_head';
        $swap->save();

        return back()->with('success', 'Вы записались на подмену. Ждёт подтверждения старшей.');
    }

    /**
     * Коллега отказывается от подмены (decline).
     * Пока реализуем как "глобальный" отказ (заявка закрывается).
     * Если нужно делать персональный отказ — надо отдельную таблицу откликов.
     */
    public function decline(Request $request, ShiftSwap $swap)
    {
        $user = $request->user();

        // Нельзя "отказывать" свою же заявку
        if ($swap->requester_id === $user->id) {
            abort(403);
        }

        // Для личной заявки — только адресат может отказаться
        if ($swap->target_user_id && $swap->target_user_id !== $user->id) {
            abort(403);
        }

        // Закрываем заявку
        $swap->status       = 'declined';
        $swap->responder_id = null;
        $swap->save();

        return back()->with('success', 'Вы отказались от подмены.');
    }
}
