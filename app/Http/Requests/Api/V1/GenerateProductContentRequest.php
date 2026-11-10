<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\AI\GenerateContentRequest;
use App\Http\Requests\Api\V1\Concerns\ResolvesOwnedProduct;

class GenerateProductContentRequest extends GenerateContentRequest
{
    use ResolvesOwnedProduct;
}
