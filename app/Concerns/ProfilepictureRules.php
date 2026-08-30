<?php

namespace App\Concerns;


use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

trait ProfilepictureRules
{
    protected function profilepictureRules(): array
    {
        return [
            'required',
            File::image()
                ->types(['jpg', 'jpeg', 'png', 'webp', 'gif'])
                ->max('2mb')
                ->dimensions(
                    Rule::dimensions()
                        ->maxWidth(4000)
                        ->maxHeight(4000),
                ),
        ];
    }
}
