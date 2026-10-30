<?php

namespace App\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

trait ProfilepictureRules
{
    /**
     * A picture is optional at registration, required when it is being changed.
     *
     * @return array<int, File|string>
     */
    protected function profilepictureRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
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
