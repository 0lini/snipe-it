<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One vendor-reported coverage plan for an asset (e.g. Dell "ProSupport
 * Plus", AppleCare+). An asset can carry several overlapping plans, so
 * these live in their own table rather than on the asset row.
 *
 * @mixin IdeHelperAssetWarrantyCoverage
 */
class AssetWarrantyCoverage extends Model
{
    protected $table = 'asset_warranty_coverages';

    protected $fillable = [
        'asset_id',
        'provider',
        'reference',
        'service_level',
        'coverage_type',
        'starts_at',
        'ends_at',
        'checked_at',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'checked_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
