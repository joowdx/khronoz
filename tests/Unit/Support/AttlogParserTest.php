<?php

namespace Tests\Unit\Support;

use App\Support\AttlogParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Ingestion front door for biometric attlog files: a malformed line must never
 * abort the rest of the file, and a value the database would refuse must never
 * be yielded as a row. A unit test and not a feature test, so the parser can
 * be pinned against those cases without a database or the framework container.
 */
class AttlogParserTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_a_tab_delimited_six_column_device_layout_file_parses_every_row(): void
    {
        $contents = "007\t2024-01-15 08:01:23\t99\t10\t20\t0\n"
            ."A17\t2024-01-15 17:30:00\t99\t11\t21\t0\n";

        $yields = $this->yields($contents, AttlogParser::LAYOUT_DEVICE);

        $this->assertCount(2, $yields);
        $this->assertSame($this->punch('007', '2024-01-15 08:01:23', 10, 20, '99'), $yields[0][0]);
        $this->assertSame($this->punch('A17', '2024-01-15 17:30:00', 11, 21, '99'), $yields[1][0]);
    }

    public function test_a_tab_delimited_four_column_standard_layout_file_parses(): void
    {
        $yields = $this->yields("007\t2024-01-15 08:01:23\t1\t0\n");

        $this->assertCount(1, $yields);
        $this->assertSame($this->punch('007', '2024-01-15 08:01:23', 1, 0), $yields[0][0]);
        $this->assertSame("007\t2024-01-15 08:01:23\t1\t0", $yields[0][1]);
    }

    public function test_a_comma_delimited_file_parses(): void
    {
        $yields = $this->yields("007,2024-01-15 08:01:23,1,0\n");

        $this->assertCount(1, $yields);
        $this->assertSame($this->punch('007', '2024-01-15 08:01:23', 1, 0), $yields[0][0]);
    }

    public function test_five_six_and_seven_column_files_all_parse(): void
    {
        $base = "007\t2024-01-15 08:01:23\t1\t0";
        $extras = [
            5 => "\t1",
            6 => "\t1\t2",
            7 => "\t1\t2\t3",
        ];

        foreach ($extras as $columns => $extra) {
            $yields = $this->yields($base.$extra."\n");

            $this->assertCount(1, $yields, "{$columns} columns");
            $this->assertSame($this->punch('007', '2024-01-15 08:01:23', 1, 0), $yields[0][0], "{$columns} columns");
        }
    }

    public function test_a_malformed_line_in_the_middle_does_not_abort_the_file(): void
    {
        $contents = "007\t2024-01-15 08:01:23\t1\t0\n"
            ."this-is-not-a-punch\n"
            ."008\t2024-01-15 08:02:00\t1\t0\n";

        $yields = $this->yields($contents);

        $this->assertCount(3, $yields);
        $this->assertSame($this->punch('007', '2024-01-15 08:01:23', 1, 0), $yields[0][0]);
        $this->assertNull($yields[1][0]);
        $this->assertSame('this-is-not-a-punch', $yields[1][1]);
        $this->assertSame($this->punch('008', '2024-01-15 08:02:00', 1, 0), $yields[2][0]);

        $rejected = array_values(array_filter($yields, fn (array $yield): bool => $yield[0] === null));
        $this->assertCount(1, $rejected);
    }

    public function test_blank_lines_and_a_trailing_newline_are_skipped(): void
    {
        $contents = "007\t2024-01-15 08:01:23\t1\t0\n"
            ."\n"
            ."008\t2024-01-15 08:02:00\t1\t0\n";

        $yields = $this->yields($contents);

        $this->assertCount(2, $yields);
        $this->assertNotNull($yields[0][0]);
        $this->assertNotNull($yields[1][0]);
    }

    public function test_an_empty_uid_is_rejected(): void
    {
        $yields = $this->yields("\t2024-01-15 08:01:23\t1\t0\n");

        $this->assertCount(1, $yields);
        $this->assertNull($yields[0][0]);
        $this->assertSame("\t2024-01-15 08:01:23\t1\t0", $yields[0][1]);
    }

    public function test_uid_is_kept_as_a_string_including_leading_zeros(): void
    {
        $contents = "007\t2024-01-15 08:01:23\t1\t0\n"
            ."A17\t2024-01-15 08:01:23\t1\t0\n";

        $yields = $this->yields($contents);

        $this->assertSame('007', $yields[0][0]['uid']);
        $this->assertSame('A17', $yields[1][0]['uid']);
    }

    public function test_state_two_hundred_fifty_five_is_accepted_and_two_hundred_fifty_six_is_rejected(): void
    {
        $contents = "007\t2024-01-15 08:01:23\t256\t0\n"
            ."007\t2024-01-15 08:01:23\t255\t0\n";

        $yields = $this->yields($contents);

        $this->assertNull($yields[0][0]);
        $this->assertSame($this->punch('007', '2024-01-15 08:01:23', 255, 0), $yields[1][0]);
    }

    public function test_mode_rejects_decimal_scientific_and_negative_values(): void
    {
        $contents = "007\t2024-01-15 08:01:23\t1\t1.5\n"
            ."007\t2024-01-15 08:01:23\t1\t1e3\n"
            ."007\t2024-01-15 08:01:23\t1\t-1\n";

        $yields = $this->yields($contents);

        $this->assertCount(3, $yields);
        $this->assertNull($yields[0][0]);
        $this->assertNull($yields[1][0]);
        $this->assertNull($yields[2][0]);
    }

    public function test_an_unpadded_hour_parses_and_is_normalised(): void
    {
        $yields = $this->yields("007\t2024-01-15 8:01:23\t1\t0\n");

        $this->assertSame('2024-01-15 08:01:23', $yields[0][0]['time']);
    }

    public function test_an_impossible_datetime_is_rejected(): void
    {
        $yields = $this->yields("007\t2024-13-45 99:99:99\t1\t0\n");

        $this->assertCount(1, $yields);
        $this->assertNull($yields[0][0]);
        $this->assertSame("007\t2024-13-45 99:99:99\t1\t0", $yields[0][1]);
    }

    public function test_a_datetime_without_seconds_defaults_them_to_zero(): void
    {
        $yields = $this->yields("007\t2024-01-15 08:01\t1\t0\n");

        $this->assertSame('2024-01-15 08:01:00', $yields[0][0]['time']);
    }

    public function test_a_slash_separated_datetime_parses(): void
    {
        $yields = $this->yields("007\t2024/01/15 08:01:23\t1\t0\n");

        $this->assertSame('2024-01-15 08:01:23', $yields[0][0]['time']);
    }

    public function test_a_file_whose_only_content_is_a_newline_yields_nothing(): void
    {
        $this->assertSame([], $this->yields("\n"));
    }

    public function test_an_unreadable_path_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array(
            (new AttlogParser)->parse(sys_get_temp_dir().'/attlog-missing-'.uniqid()),
            false,
        );
    }

    /**
     * @return list<array{0: array{uid: string, time: string, state: int, mode: int}|null, 1: string}>
     */
    private function yields(string $contents, string $layout = AttlogParser::LAYOUT_STANDARD): array
    {
        return iterator_to_array((new AttlogParser)->parse($this->path($contents), $layout), false);
    }

    private function path(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'attlog_');

        file_put_contents($path, $contents);

        $this->paths[] = $path;

        return $path;
    }

    /**
     * @return array{uid: string, time: string, state: int, mode: int}
     */
    private function punch(string $uid, string $time, int $state, int $mode, ?string $device = null): array
    {
        return ['uid' => $uid, 'time' => $time, 'device' => $device, 'state' => $state, 'mode' => $mode];
    }
}
