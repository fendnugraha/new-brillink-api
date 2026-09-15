<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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
            'phone' => 'nullable|string|unique:contacts,phone|min:10|max:15|regex:/^[0-9\-\+\s\(\)]+$/',
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
        $contact = Contact::findOrFail($id);
        $user = $contact->user;

        // Build aturan validasi
        $emailRules = [
            'nullable',
            'email',
            'max:100',
            Rule::unique('contacts', 'email')->ignore($contact->id),
        ];

        // Jika kontak ini punya akun User, pastikan email unik juga di tabel users
        if ($user) {
            $emailRules[] = Rule::unique('users', 'email')->ignore($user->id);
        }

        $request->validate(
            [
                'name' => [
                    'required',
                    'string',
                    'max:60',
                    Rule::unique('contacts', 'name')->ignore($contact->id),
                ],
                'email' => $emailRules,
                'phone' => [
                    'nullable',
                    'min:10',
                    'max:15',
                    'regex:/^[0-9\-\+\s\(\)]+$/',
                    Rule::unique('contacts', 'phone')->ignore($contact->id),
                ],
                'telegram_chat_id' => [
                    'nullable',
                    'string',
                    'max:100',
                    Rule::unique('contacts', 'telegram_chat_id')->ignore($contact->id),
                ],
                'address' => 'nullable|string|max:160',
                'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048'
            ],
            [
                'name.unique' => 'Nama kontak sudah digunakan.',
                'phone.unique' => 'Nomor telepon sudah digunakan.',
                'email.unique' => 'Email sudah digunakan oleh kontak atau akun pengguna lain.',
                'telegram_chat_id.unique' => 'ID obrolan Telegram sudah digunakan.'
            ]
        );

        $newEmail = $request->input('email');
        $emailChanged = $contact->email !== $newEmail; // Cek apakah email berubah

        // 1. Update data kolom teks Kontak
        $contact->email = $newEmail;
        $contact->name = $request->input('name');
        $contact->phone = $request->input('phone');
        $contact->address = $request->input('address');
        $contact->telegram_chat_id = $request->input('telegram_chat_id');

        // 2. Update relasi User (jika kontak memiliki akun User)
        if ($user) {
            $userData = [
                'name' => $request->input('uname'),
                'email' => $newEmail,
            ];

            // Jika email berubah, reset verifikasi email
            if ($emailChanged) {
                $userData['email_verified_at'] = null;
            }

            $user->update($userData);

            // (Opsional) Kirim ulang email verifikasi jika email berubah
            // if ($emailChanged && $newEmail) {
            //     $user->sendEmailVerificationNotification();
            // }
        }

        // 3. Handle Upload Photo
        if ($request->hasFile('photo')) {
            if ($contact->photo && Storage::disk('public')->exists($contact->photo)) {
                Storage::disk('public')->delete($contact->photo);
            }
            $contact->photo = $request->file('photo')->store('contact', 'public');
        }

        $contact->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Contact updated successfully',
            'data' => $contact->fresh(['user']) // Mengembalikan data kontak beserta relasi user
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
        $contacts = Contact::with('employee:id,contact_id,status')->orderBy('name', 'asc')->get();
        return new AccountResource($contacts, true, "Successfully fetched contacts");
    }
}
