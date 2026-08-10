<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\CashFlow;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CashFlowController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $start = Carbon::parse($request->start_date)->startOfDay();
        $end = Carbon::parse($request->end_date)->endOfDay();

        $cashFlows = CashFlow::whereBetween('date_issued', [$start, $end])->get();
        $cashFlowGrouped = CashFlow::selectRaw('category, is_corporate, SUM(amount) as total')->whereBetween('date_issued', [$start, $end])->groupBy('category', 'is_corporate')->get();

        $data = [
            'cash_flows' => $cashFlows,
            'cash_flows_grouped' => $cashFlowGrouped
        ];
        return new AccountResource($data, true, "Successfully fetched cash flows from {$start->format('Y-m-d')} to {$end->format('Y-m-d')}");
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
            'date_issued' => 'required|date',
            'type' => 'required|in:income,expense',
            'amount' => 'required|numeric|min:0',
            'description' => 'required|string|max:255',
            'category' => 'required|string|max:255'
        ]);

        DB::beginTransaction();
        try {
            $cashFlow = CashFlow::create([
                'date_issued' => $request->date_issued,
                'type' => $request->type,
                'amount' => $request->amount,
                'description' => $request->description,
                'category' => $request->category,
                'user_id' => auth()->id(),
                'is_corporate' => $request->is_corporate ?? false
            ]);

            DB::commit();
            return response()->json([
                'data' => $cashFlow,
                'success' => true,
                'message' => 'Cash flow created successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create cash flow'
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(CashFlow $cashFlow)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(CashFlow $cashFlow)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CashFlow $cashFlow)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CashFlow $cashFlow)
    {
        //
    }
}
