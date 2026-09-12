<?php

namespace Tests\Unit\Enums;

use App\Enums\Concerns\HasChoices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;
use SplFileInfo;

class EnumLabelContractTest extends TestCase
{
    /** @return array<string, array{0: class-string}> */
    public static function labelled(): array
    {
        $cases = [];

        foreach (glob(__DIR__.'/../../../app/Enums/*.php') ?: [] as $path) {
            $class = 'App\\Enums\\'.basename($path, '.php');

            if (enum_exists($class) && method_exists($class, 'label')) {
                $cases[basename($path, '.php')] = [$class];
            }
        }

        return $cases;
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('labelled')]
    public function test_a_labelled_enum_offers_its_cases_as_choices(string $enum): void
    {
        $this->assertContains(
            HasChoices::class,
            (new ReflectionEnum($enum))->getTraitNames(),
            "{$enum} has a label() but does not use HasChoices, so no picker can be built from it without restating its cases.",
        );

        $choices = $enum::choices();

        $this->assertSame(
            array_map(fn (object $case) => $case->value, $enum::cases()),
            array_column($choices, 'value'),
            'choices() must cover every case, in declaration order.',
        );

        foreach ($choices as $choice) {
            $this->assertSame($enum::from($choice['value'])->label(), $choice['label']);
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('labelled')]
    public function test_no_label_is_written_out_in_typescript(string $enum): void
    {
        $multiWord = array_filter(
            array_map(fn (object $case) => $case->label(), $enum::cases()),
            fn (string $label) => str_contains(trim($label), ' '),
        );

        if ($multiWord === []) {
            $this->addToAssertionCount(1);

            return;
        }

        $offences = [];

        foreach ($this->frontend() as $file) {
            $contents = $this->code($file->getPathname());

            foreach ($multiWord as $label) {
                // The label as a TS string literal, in either quote style. A
                // label inside a comment or a hint sentence is not what this
                // looks for, which is why the quotes are part of the pattern.
                if (str_contains($contents, "'{$label}'") || str_contains($contents, "\"{$label}\"")) {
                    $offences[] = str_replace($this->root().'/', '', $file->getPathname()).": '{$label}'";
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ["{$enum}'s labels are restated in TypeScript. Ship {value,label} from the resource, or the enum's choices() from the controller, and render what the page is given:"],
            $offences,
        )));
    }

    private function code(string $path): string
    {
        $contents = file_get_contents($path) ?: '';

        // Block comments first (JSX `{/* … */}` included, since the braces sit
        // outside the comment), then line comments.
        $contents = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;

        return preg_replace('#^\s*//.*$#m', '', $contents) ?? $contents;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<SplFileInfo> */
    private function frontend(): array
    {
        $files = [];

        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root().'/resources/js')
        );

        foreach ($directory as $file) {
            if (! $file instanceof SplFileInfo || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            // The marketing pages are not driven by these enums — they are a
            // fabricated November on the landing page — so a phrase of theirs
            // that happens to read like a label is copy, not drift.
            if (str_contains($file->getPathname(), '/components/marketing/')) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }
}
