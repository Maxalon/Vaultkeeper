<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row table holding the bcrypt-hashed Horizon dashboard password.
 * Created by the /horizon-setup flow on first access; cleared by the
 * `horizon:reset-credentials` artisan command when the password is lost.
 */
class HorizonAdmin extends Model
{
    protected $fillable = ['password_hash'];

    protected $hidden = ['password_hash'];
}
