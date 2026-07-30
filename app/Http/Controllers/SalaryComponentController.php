<?php

namespace App\Http\Controllers;

use App\Models\SalaryComponent;
use Illuminate\Http\Request;

class SalaryComponentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
        $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'type' => 'required|in:allowance,deduction',
            'employee_id' => 'required|exists:employees,id'
        ]);

        $salaryComponent = SalaryComponent::create($request->all());

        return response()->json(['success' => true, 'data' => $salaryComponent, 'message' => 'Salary component created successfully'], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(SalaryComponent $salaryComponent)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SalaryComponent $salaryComponent)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SalaryComponent $salaryComponent)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SalaryComponent $salaryComponent)
    {
        $salaryComponent->delete();

        return response()->json(['success' => true, 'message' => 'Salary component deleted successfully']);
    }
}
