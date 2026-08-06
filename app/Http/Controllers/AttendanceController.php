<?php

namespace App\Http\Controllers;

use App\Helpers\DistanceHelper;
use App\Models\Attendance;
use App\Models\Contact;
use App\Models\Warehouse;
use App\Services\AttendanceRatingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

class AttendanceController extends Controller
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Attendance $attendance)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Attendance $attendance)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Attendance $attendance)
    {
        $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'time_in' => 'required',
        ]);

        if (auth()->user()->role !== 'Super Admin') {
            return response()->json(['success' => false, 'message' => 'You are not authorized.'], 403);
        }

        try {
            $attendance->update([
                'contact_id' => $request->contact_id,
                'time_in' => Carbon::parse($request->time_in)->format('H:i:s'),
                'approval_status' => $request->approval_status,
            ]);
            return response()->json(['success' => true, 'data' => $attendance, 'message' => 'Attendance updated successfully']);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Attendance $attendance)
    {
        //
    }

    public function getWarehouseAttendance(string $date)
    {
        $warehouses = Warehouse::with([
            'contact:id,name',
            'attendance' => function ($query) use ($date) {
                $query->whereDate('date', Carbon::parse($date));
            },
            'attendance.contact:id,name',  // tidak pakai closure
            'zone'
        ])
            ->where('id', '!=', 1)
            ->where('status', '!=', 0)
            ->orderBy('name', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $warehouses]);
    }

    public function getAttendanceByContact(Request $request)
    {
        $warehouseId = auth()->user()->warehouse_id;
        $date = Carbon::parse($request->date) ?? now()->format('Y-m-d');

        $att = Attendance::where('warehouse_id', $warehouseId)->whereDate('date', $date)->first();
        Log::info($att);
        $contactId = auth()->user()->contact_id ?? $att->contact_id;

        Log::info("Contact ID: " . $contactId);
        $parsed = Carbon::parse($date);

        $start = $parsed->copy()->startOfMonth();
        $end   = $parsed->copy()->endOfMonth();

        $attendance = Attendance::with('contact.employee', 'contact.employee_receivables_sum', 'contact.installment_receivables_sum')->whereBetween('date', [$start, $end])
            ->where('contact_id', $contactId)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attendance
        ]);
    }

    public function getContactDetails(Request $request)
    {
        $warehouseId = auth()->user()->warehouse_id;
        $date = Carbon::parse($request->date) ?? now()->format('Y-m-d');
        $parsed = Carbon::parse($date);

        $start = $parsed->copy()->startOfMonth();
        $end   = $parsed->copy()->endOfMonth();

        $att = Attendance::where('warehouse_id', $warehouseId)->whereDate('date', $date)->first();
        Log::info($att);
        $contactId = $request->contact_id ?? $att->contact_id;

        $contact = Contact::with(['employee', 'employee_receivables_sum', 'installment_receivables_sum', 'attendances' => fn($q) => $q->whereBetween('date', [$start, $end])])->findOrFail($contactId);

        return response()->json([
            'success' => true,
            'data' => $contact
        ]);
    }

    public function createAttendance(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|max:2048', // max 2MB (aman karena sudah compress)
            'latitude' => 'required',
            'longitude' => 'required',
        ]);

        $warehouseId = auth()->user()->role === 'Cashier' ? auth()->user()->warehouse_id : $request->warehouse_id;
        Log::info("Warehouse ID: " . $warehouseId);
        $office = Warehouse::with('zone')->findOrFail($warehouseId);

        $distance = DistanceHelper::distanceInMeters(
            $request->latitude,
            $request->longitude,
            $office->latitude,
            $office->longitude
        );
        Log::info("Distance: " . $distance);

        $contact = auth()->user()->contact_id ?? null;

        // Batas radius dalam meter (misalnya 50m)
        $maxRadius = 50;

        if ($distance > $maxRadius && auth()->user()->role === 'Cashier') {
            return response()->json([
                'success' => false,
                'message' => "Gagal, Anda berada di luar radius cabang. Jarak: " . Number::format($distance, 2) . " meter"
            ], 422);
        }

        $path = $request->file('photo')->store('attendance', 'public');

        $time_in = Carbon::parse($request->time_in); 
        $work_start = $time_in->copy()->setTimeFromTimeString($office->opening_time);
        $late_threshold = $work_start->copy()->addMinute();

        $is_late = $time_in->gt($late_threshold);
        $status = $is_late ? 'Late' : 'Approved';

        $late_minutes = $is_late ? $time_in->diffInMinutes($work_start) : 0;

        Log::info("User ID: " . auth()->id() . " | Status: {$status} | Terlambat: {$late_minutes} menit");
        $timeInFormatted = Carbon::parse($request->time_in)->format('H:i:s');

        DB::beginTransaction();
        try {
            Attendance::create([
                'user_id' => auth()->id(),
                'contact_id' => $contact ?? null,
                'warehouse_id' => $warehouseId,
                'photo'   => $path,
                'time_in' => $timeInFormatted,
                'date'    => now(),
                'ip'      => $request->ip(),
                'note'    => $request->note,
                'longitude' => $request->longitude,
                'latitude' => $request->latitude,
                'approval_status' => $status
            ]);

            // Langsung update melalui instansi user yang sedang login
            if (auth()->user()->role !== 'Cashier') {
                auth()->user()->update(['warehouse_id' => $warehouseId]);
            }

            Warehouse::changeLockStatus($warehouseId, 1);

            DB::commit();

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function createAttendanceManually(Request $request)
    {
        $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'time_in' => 'required',
        ]);

        DB::beginTransaction();
        try {
            Attendance::create([
                'user_id' => $request->user_id ?? auth()->id(),
                'contact_id' => $request->contact_id,
                'warehouse_id' => $request->warehouse_id ?? null,
                'photo'   => null,
                'time_in' => Carbon::parse($request->time_in)->format('H:i:s') ?? Carbon::parse(now())->format('H:i:s'),
                'date'    => $request->date ?? now(),
                'approval_status' => $request->approval_status ?? 'Approved'
            ]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Attendance created successfully'], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function attendanceCheck(string $date, int $userId)
    {
        $attendance = Attendance::with('contact:id,name')->where('user_id', $userId)->whereDate('date', $date)->first();
        return response()->json(['success' => true, 'data' => $attendance]);
    }

    function generateCalendarDays(Int $year, Int $month)
    {
        $start = Carbon::create($year, $month, 1);
        $end   = $start->copy()->endOfMonth();

        $days = [];
        while ($start->lte($end)) {
            $days[] = $start->format('Y-m-d');
            $start->addDay();
        }

        return $days;
    }

    public function getAttendanceMonthly(string $date)
    {
        // 1. Parsing Tanggal dengan Aman
        try {
            $parsedDate = Carbon::parse($date);
        } catch (\Exception $e) {
            $parsedDate = now();
        }

        $year  = $parsedDate->year;
        $month = $parsedDate->month;
        $days  = $this->generateCalendarDays($year, $month);

        // 2. Eager Loading Relasi 'attendances.warehouse' untuk cegah N+1 Query
        $contacts = Contact::with([
            'user:id,name,warehouse_id',
            'attendances' => function ($q) use ($year, $month) {
                $q->whereYear('date', $year)
                    ->whereMonth('date', $month)
                    ->with('warehouse'); // Eager load warehouse!
            }
        ])
            ->whereHas('attendances', function ($q) use ($year, $month) {
                $q->whereYear('date', $year)
                    ->whereMonth('date', $month);
            })
            ->orderBy('name')
            ->get();

        // 3. Mapping Data
        $contacts->transform(function ($contact) use ($days) {
            $mapped = [];
            foreach ($days as $day) {
                $mapped[$day] = null;
            }

            foreach ($contact->attendances as $att) {
                // Pastikan format date disamakan dengan string 'Y-m-d' agar key-nya cocok!
                $formattedDate = Carbon::parse($att->date)->format('Y-m-d');

                if (array_key_exists($formattedDate, $mapped)) {
                    $mapped[$formattedDate] = [
                        'time_in'    => $att->time_in,
                        'status'     => $att->approval_status,
                        'photo_url'  => $att->photo_url,
                        'zone'       => $att->warehouse->warehouse_zone_id ?? null
                    ];
                }
            }

            $contact->attendance_by_date = $mapped;
            unset($contact->attendances);

            return $contact;
        });

        // 4. Bungkus dalam 'data' agar sesuai dengan Axios/SWR kamu res.data?.data
        return response()->json([
            'status' => 'success',
            'data'   => [
                'days'      => $days,
                'employees' => $contacts
            ]
        ]);
    }

    public function attendanceRating(
        AttendanceRatingService $service,
        int $employeeId,
        Request $request
    ) {
        return response()->json(
            $service->calculate(
                $employeeId,
                $request->month,
                $request->year
            )
        );
    }
}
