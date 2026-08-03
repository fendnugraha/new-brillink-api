<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\Delivery;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $deliveries = Delivery::with([
            'journal:id,reference_no,amount,notes',
            // Load COA beserta Warehouse-nya sekaligus
            'sourceAccount:id,code,name,warehouse_id',
            'sourceAccount.warehouse:id,code,name',
            'destinationAccount:id,code,name,warehouse_id',
            'destinationAccount.warehouse:id,code,name',
            'courier:id,name,phone',
            'receiver:id,name'
        ])
            ->latest()
            ->get();

        return new AccountResource($deliveries, true, "Successfully retrieved deliveries");
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Delivery $delivery)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Delivery $delivery)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Delivery $delivery)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Delivery $delivery)
    {
        //
    }
}
