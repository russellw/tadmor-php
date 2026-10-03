<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A login user, over the shared users table. */
class User extends Model
{
    // created_at and updated_at are maintained by the database.
    public $timestamps = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_admin' => 'boolean'];
    }

    /** The User representation of spec/api.md §3. */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'full_name' => $this->full_name,
            'is_admin' => $this->is_admin,
        ];
    }
}
