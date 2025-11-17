<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Department;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ---- Отделения ----
        $surgery = Department::firstOrCreate(
            ['id' => 1],
            [
                'name'  => 'Хирургия',
                'color' => '#F15780',
            ]
        );

        $therapy = Department::firstOrCreate(
            ['id' => 2],
            [
                'name'  => 'Терапия',
                'color' => '#80D2F9',
            ]
        );

        // ---- Админ (старшая медсестра) ----
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'           => 'Старшая медсестра',
                'password'       => Hash::make('password'), // пароль: password
                'isAdmin'        => 1,
                'department_id'  => $surgery->id,
                'standart_hours' => '36',
            ]
        );

        // ---- Обычная медсестра ----
        User::updateOrCreate(
            ['email' => 'nurse@example.com'],
            [
                'name'           => 'Анна Петрова',
                'password'       => Hash::make('password'), // пароль: password
                'isAdmin'        => 0,
                'department_id'  => $therapy->id,
                'standart_hours' => '36',
            ]
        );
    }
}
