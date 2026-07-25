<?php
// app/Models/InvoiceLine.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One snapshotted line of a filed invoice — see Invoice. */
class InvoiceLine extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'invoice_id', 'description', 'hsn_code', 'qty', 'rate',
        'taxable_value', 'gst_rate_percent', 'cgst', 'sgst', 'line_total',
    ];

    protected $casts = [
        'qty' => 'integer',
        'rate' => 'decimal:2',
        'taxable_value' => 'decimal:2',
        'gst_rate_percent' => 'decimal:2',
        'cgst' => 'decimal:2',
        'sgst' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];
}
