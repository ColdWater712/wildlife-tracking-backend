<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TourRoute extends Model
{
    protected $table = 'tour_routes';

    protected $primaryKey = 'route_id';

    protected $fillable = [
        'code', 'slug', 'name', 'entry_point', 'summary', 'description', 'difficulty', 'duration_hours', 'duration_days',
        'distance_km', 'max_group_size', 'min_age', 'meeting_point', 'meeting_lat', 'meeting_lon', 'start_time',
        'guide_fee_per_person', 'itinerary', 'includes', 'bring_list', 'rules', 'wildlife', 'waypoints', 'zone_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'itinerary' => 'array',
            'includes' => 'array',
            'bring_list' => 'array',
            'rules' => 'array',
            'wildlife' => 'array',
            'waypoints' => 'array',
            'distance_km' => 'float',
            'meeting_lat' => 'float',
            'meeting_lon' => 'float',
            'guide_fee_per_person' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function zone()
    {
        return $this->belongsTo(GeoZone::class, 'zone_id', 'zone_id');
    }

    public function shifts()
    {
        return $this->hasMany(GuideShift::class, 'route_id', 'route_id');
    }

    public function reviews()
    {
        return $this->hasMany(TourReview::class, 'route_id', 'route_id');
    }
}
