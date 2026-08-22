<?php

namespace App\Concerns;


use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Support\Facades\Log;


trait ProfilepictureRules
{

    protected function profilepictureRules(): array
    {

        return [
            'required',
            File::image()->max('2mb')->dimensions(Rule::dimensions()->maxWidth(2000)->maxHeight(2000))
        ];
    }
}
