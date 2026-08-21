<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Appends('contact_photo_url')]
class Contact extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(function (Contact $contact) {
            if ($contact->photo) {
                // Hapus berkas dari disk 'public'
                if (Storage::disk('public')->exists('contact/' . $contact->photo)) {
                    Storage::disk('public')->delete('contact/' . $contact->photo);
                }
            }
        });
    }

    /**
     * Accessor Foto URL (Sintaks Modern Laravel 9+)
     */
    protected function contactPhotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->photo
                ? Storage::disk('public')->url($this->photo)
                : null
        );
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function finances()
    {
        return $this->hasMany(Finance::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employee_receivables()
    {
        return $this->hasMany(Finance::class)->where('finance_type', 'EmployeeReceivable');
    }

    public function employee_receivables_sum()
    {
        return $this->hasOne(Finance::class, 'contact_id')
            ->where('finance_type', 'EmployeeReceivable')
            ->select('contact_id', DB::raw('SUM(bill_amount - payment_amount) as total'))
            ->groupBy('contact_id');
    }

    public function installment_receivables_sum()
    {
        return $this->hasOne(Finance::class, 'contact_id')
            ->where('finance_type', 'InstallmentReceivable')
            ->select('contact_id', DB::raw('SUM(bill_amount - payment_amount) as total'))
            ->groupBy('contact_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'id', 'contact_id');
    }
}
