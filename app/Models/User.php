<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
// use Database\Factories\UserFactory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'warehouse_id', 'role', 'status', 'contact_id', 'fcm_token', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
#[Appends(['has_checked_in'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function journals()
    {
        return $this->hasMany(Journal::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function getHasCheckedInAttribute()
    {
        return $this->attendances()
            ->whereDate('created_at', Carbon::today())
            ->exists();
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
