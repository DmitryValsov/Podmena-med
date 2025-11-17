<?php

namespace App\Http\Controllers;
use App\Models\Department;
use App\Models\User;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DepartmentController extends Controller
{
    public function index(Department $department, User $user)
    {

        //$department = Department::find(1);
        //$user = $department->users;


        return Inertia::render('department/Index', [
            'department' => $department = Department::all(),
            'user' => $user = User::all(),
        ]);
    }

    public function create()
    {
        return Inertia::render('department/Create', [
        ]);
    }

    public function store(Request $request)
    {
        Department::create($request->validate([
            'name' => ['required', 'max:50'],
            'quantity_doctors' => ['required', 'max:50'],
        ]));

        return to_route('department.index');
    }


}
