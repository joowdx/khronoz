<?php

/**
 * Only the messages the interface actually shows, overriding the framework's.
 * Laravel's FileLoader merges this file over its own defaults
 * (TranslationServiceProvider registers the loader with both paths and
 * array_replace_recursive's them), so anything absent here keeps Laravel's
 * wording.
 *
 * These are the short forms. A field error renders on the label row beside its
 * label (resources/js/components/field.tsx), which holds exactly one line —
 * so the message never repeats the field's name (":attribute" is deliberately
 * gone) and never explains the rule. What happened, in as few words as read at
 * a glance; how to fix it is the label plus the control.
 */

return [
    'required' => 'Required',
    'email' => 'Enter a valid email',
    'unique' => 'Already taken',

    /*
     * `confirmed` only ever guards the password pair in this application, so
     * it can name it. Revisit if a second confirmed field ever appears.
     */
    'confirmed' => 'Passwords don\'t match',

    'min' => [
        'array' => 'Choose at least :min',
        'numeric' => 'At least :min',
        'string' => 'At least :min characters',
    ],
    'max' => [
        'array' => 'Choose at most :max',
        'numeric' => 'At most :max',
        'string' => 'At most :max characters',
    ],
    'between' => [
        'numeric' => 'Between :min and :max',
        'string' => 'Between :min and :max characters',
    ],

    'alpha_dash' => 'Letters, numbers, dashes and underscores only',
    'alpha_num' => 'Letters and numbers only',
    'date' => 'Enter a valid date',
    'in' => 'Not one of the choices',
    'integer' => 'Enter a whole number',
    'numeric' => 'Enter a number',
    'boolean' => 'Choose yes or no',
    'exists' => 'Not found',
    'array' => 'Unexpected value',
    'string' => 'Unexpected value',

    'password' => [
        'letters' => 'Include a letter',
        'mixed' => 'Include an upper and a lower case letter',
        'numbers' => 'Include a number',
        'symbols' => 'Include a symbol',
        'uncompromised' => 'This password has appeared in a breach. Choose another.',
    ],

    /*
     * Per-attribute wording, for cases where the rule's generic short form
     * would be wrong on the label row. A permission set is a group of
     * checkboxes, so "Required" reads as if one box were missing.
     */
    'custom' => [
        'permissions' => [
            'required' => 'Choose at least one',
        ],
    ],

    'attributes' => [
        'password_confirmation' => 'confirmation',
    ],
];
