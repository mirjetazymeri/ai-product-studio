<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'reference', 'type', 'plan', 'amount_cents', 'currency', 'status', 'description'])]
class DemoBillingTransaction extends Model {}
