<?php

namespace App\Http\Controllers;

use App\Models\EmployeeSchedule;
use Illuminate\Http\Request;

class EmployeeScheduleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $employeeSchedules = EmployeeSchedule::with('employee.contact:id,name')->get();
        return response()->json($employeeSchedules);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'date' => 'required|date',
            'end_date' => 'nullable|date|after:date',
            'type' => 'required|in:weekly_off,leave,company_event',
            'title' => 'nullable|string|max:255',
            'status' => 'required|in:pending,approved,rejected',
            'approved_by' => 'nullable|exists:users,id'
        ]);

        $employeeSchedule = EmployeeSchedule::create($validated);
        return response()->json($employeeSchedule, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(EmployeeSchedule $employeeSchedule)
    {
        return response()->json($employeeSchedule);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EmployeeSchedule $employeeSchedule)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, EmployeeSchedule $employeeSchedule)
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'date' => 'required|date',
            'end_date' => 'nullable|date|after:date',
            'type' => 'required|in:weekly_off,leave,company_event',
            'title' => 'nullable|string|max:255',
            'status' => 'required|in:pending,approved,rejected',
            'approved_by' => 'nullable|exists:users,id'
        ]);

        $employeeSchedule->update($validated);
        return response()->json($employeeSchedule);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EmployeeSchedule $employeeSchedule)
    {
        $employeeSchedule->delete();
        return response()->json(null, 204);
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'schedules' => 'required|array',
            'schedules.*.employee_id' => 'nullable|exists:employees,id',
            'schedules.*.date' => 'required|date',
            'schedules.*.type' => 'required|in:weekly_off,leave,company_event',
        ]);

        foreach ($request->schedules as $item) {
            EmployeeSchedule::updateOrCreate(
                [
                    'employee_id' => $item['employee_id'],
                    'date' => $item['date'],
                ],
                [
                    'type' => $item['type'],
                    'title' => $item['title'] ?? null,
                    'status' => $item['status'] ?? 'approved',
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil diimport ke database.'
        ]);
    }
}
