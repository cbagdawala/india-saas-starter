<?php

namespace App\Modules\Invoicing\Models;

use Illuminate\Database\Eloquent\Model;

/** Counter row per seller/series/financial year. Only InvoiceNumberAllocator touches it (via the query builder). */
class InvoiceSequence extends Model {}
