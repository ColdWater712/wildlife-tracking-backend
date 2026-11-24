<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nama peserta saja; nomor identitas sengaja tidak disimpan (data pribadi). */
class BookingParticipant extends Model
{
    public $timestamps = false;

    protected $table = 'booking_participants';

    protected $primaryKey = 'participant_id';

    protected $fillable = ['booking_id', 'full_name', 'visitor_type', 'age'];
}
