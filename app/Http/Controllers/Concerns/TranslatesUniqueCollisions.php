<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait TranslatesUniqueCollisions
{
    /**
     * @param  array<string, string>  $fields  constraint-name fragment => field name
     */
    protected function translatingCollisions(array $fields, callable $write): mixed
    {
        try {

            return DB::transaction($write);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            foreach ($fields as $fragment => $field) {
                if (str_contains($e->getMessage(), $fragment)) {
                    throw ValidationException::withMessages([
                        $field => [trans('validation.unique', ['attribute' => $field])],
                    ]);
                }
            }

            throw $e;
        }
    }
}
