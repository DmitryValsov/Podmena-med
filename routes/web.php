<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

// Контроллеры
use App\Http\Controllers\Admin\ScheduleController; // панель старшей медсестры
use App\Http\Controllers\UserController;           // личный кабинет медсестры

/*
|--------------------------------------------------------------------------
| Главная страница (Welcome после авторизации)
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->middleware(['auth', 'verified'])->name('home');

/*
|--------------------------------------------------------------------------
| Дашборд
|--------------------------------------------------------------------------
*/
Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

/*
|--------------------------------------------------------------------------
| Настройки (отдельный файл)
|--------------------------------------------------------------------------
*/
require __DIR__.'/settings.php';

/*
|--------------------------------------------------------------------------
| Панель старшей медсестры — админ-расписание
| URL: /admin/...
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        // Страница шахматки
        Route::get('/schedule', [ScheduleController::class, 'index'])
            ->name('schedule.index');

        // Сохранить одну ячейку
        Route::post('/schedule/cell', [ScheduleController::class, 'saveCell'])
            ->name('schedule.cell.save');

        // Очистить конкретную ячейку
        Route::post('/schedule/cell-clear', [ScheduleController::class, 'clearCell'])
            ->name('schedule.cell.clear');

        // Очистить месяц
        Route::post('/schedule/clear-month', [ScheduleController::class, 'clearMonth'])
            ->name('schedule.clear-month');

        // Сгенерировать демо-данные
        Route::post('/schedule/seed-demo', [ScheduleController::class, 'seedDemo'])
            ->name('schedule.seed-demo');

        // --- Заявки на подмену: действия старшей медсестры ---

        // Утвердить подмену
        Route::post('/schedule/swaps/{swap}/approve', [ScheduleController::class, 'approveSwap'])
            ->name('schedule.swaps.approve');

        // Отклонить подмену
        Route::post('/schedule/swaps/{swap}/decline', [ScheduleController::class, 'declineSwap'])
            ->name('schedule.swaps.decline');
    });

/*
|--------------------------------------------------------------------------
| Личный кабинет медсестры — расписание и подмены
| Vue: resources/js/Pages/user/Schedule.vue
| URL: /user/...
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])
    ->prefix('user')
    ->name('user.')
    ->group(function () {

        // Страница расписания медсестры
        Route::get('/schedule', [UserController::class, 'index'])
            ->name('schedule');

        // Создание заявки на подмену (форма "Быстрая подмена" + модалка)
        // фронт: router.post('/user/swap', ...)
        Route::post('/swap', [UserController::class, 'store'])
            ->name('swap.store');

        // Коллега принимает заявку
        // фронт: router.post(`/user/swap/${rq.id}/accept`, ...)
        Route::post('/swap/{swap}/accept', [UserController::class, 'accept'])
            ->name('swap.accept');

        // Коллега отклоняет заявку
        // фронт: router.post(`/user/swap/${rq.id}/decline`, ...)
        Route::post('/swap/{swap}/decline', [UserController::class, 'decline'])
            ->name('swap.decline');
    });
