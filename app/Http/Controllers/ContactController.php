<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\Contact;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $contacts = Contact::orderBy('name', 'asc')
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('address', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            })
            ->paginate(10)
            ->onEachSide(0);
        return new AccountResource($contacts, true, "Successfully fetched contacts");
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
            'name' => 'required|string|max:60',
            'phone' => 'nullable|string|min:10|max:15|regex:/^[0-9\-\+\s\(\)]+$/',
            'address' => 'nullable|string|max:160',
        ]);

        $contact = Contact::create([
            'name' => $request['name'],
            'phone' => $request['phone'],
            'address' => $request['address'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Contact created successfully',
            'data' => $contact
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'name' => 'required|string|max:60',
            'phone' => 'nullable|string|min:10|max:15|regex:/^[0-9\-\+\s\(\)]+$/',
            'address' => 'nullable|string|max:160',
            'telegram_chat_id' => 'nullable|string|max:100',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048'
        ]);

        $contact = Contact::findOrFail($id);

        // Update text fields
        $contact->name = $request->input('name');
        $contact->phone = $request->input('phone');
        $contact->address = $request->input('address');
        $contact->telegram_chat_id = $request->input('telegram_chat_id');

        // Handle photo upload only if a file is present
        if ($request->hasFile('photo')) {
            $contact->photo = $request->file('photo')->store('contact', 'public');
        }

        $contact->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Contact updated successfully',
            'data' => $contact
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Contact $contact)
    {
        $transactionsExist = $contact->transactions()->exists();
        $financesExist = $contact->finances()->exists();

        if ($transactionsExist || $financesExist || $contact->id === 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete contact with existing transactions or finances'
            ], 400);
        }

        $contact->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Contact deleted successfully'
        ]);
    }

    public function getAllContacts()
    {
        $contacts = Contact::orderBy('name', 'asc')->get();
        return new AccountResource($contacts, true, "Successfully fetched contacts");
    }
}
