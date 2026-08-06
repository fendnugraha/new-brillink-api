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
            'journal:id,invoice,description,amount',
            
            'sourceAccount:id,name,warehouse_id',
            'sourceAccount.warehouse:id,name,latitude,longitude',
            'destinationAccount:id,name,warehouse_id',
            'destinationAccount.warehouse:id,name,latitude,longitude',
            
            'courier:id,contact_id',
            'courier.contact:id,name,phone,user_id',
            'courier.contact.user:id,name,email,latitude,longitude',
            
            'receiver:id,contact_id',
            'receiver.contact:id,name,phone,user_id',
            'receiver.contact.user:id,name,email,latitude,longitude',
        ])
        ->whereDate('created_at', today())
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
