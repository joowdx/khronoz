<?php

namespace App\Support;

use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use SplFileObject;

/**
 * Streaming parser for biometric "attlog" punch files: one punch per line,
 * tab- or comma-delimited, in either the four-column standard layout or the
 * device layout that inserts a device number after the timestamp.
 *
 * A malformed line must never abort the file — payroll ingestion cannot afford
 * to drop the rest of a day's punches because one row is junk — so every bad
 * line yields `[null, $rawLine]` and parsing continues. The only exception is an
 * unreadable path, which is an operator error rather than bad punch data.
 *
 * Memory stays flat regardless of file size: the predecessor system blew up a
 * queue worker by loading the whole file (and a dedupe set) at once.
 *
 * `uid` is kept as a string exactly as written so that `007`, `7` and `A17`
 * remain three different device users; casting to int would collapse the
 * first two.
 *
 * `state` and `mode` are matched against `/^\d+$/` before casting because
 * `is_numeric` accepts `1.5` and `1e3`, which then blow up at the database after
 * earlier rows have already committed.
 *
 * `device` is **returned, never discarded**. An attlog carries no ULID, so that
 * column is the file's one and only statement of which scanner produced these
 * punches — there is no other indicator anywhere in it. A parser that reads
 * past it leaves the caller trusting whichever terminal a human happened to
 * pick, and a file imported into the wrong one attributes every punch to the
 * wrong device without a single complaint. It is null under LAYOUT_STANDARD,
 * where the file genuinely does not say.
 */
final class AttlogParser
{
    public const LAYOUT_STANDARD = 'standard';

    public const LAYOUT_DEVICE = 'device';

    /**
     * The `!` prefix resets unspecified fields to zero, so `Y-m-d H:i` gets
     * seconds of 00 rather than the current second. Tried in order; the first
     * that parses with zero errors and zero warnings wins. Unpadded hours are
     * accepted by `Y-m-d H:i:s` itself — a raw-string round-trip would reject
     * them, because the object reformats `8:01:23` as `08:01:23`.
     *
     * @var list<string>
     */
    private const TIME_FORMATS = [
        '!Y-m-d H:i:s',
        '!Y-m-d H:i',
        '!Y/m/d H:i:s',
    ];

    /**
     * @return Generator<int, array{0: array{uid: string, time: string, device: string|null, state: int, mode: int}|null, 1: string}>
     */
    public function parse(string $path, string $layout = self::LAYOUT_STANDARD): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Attlog path is not a readable file: {$path}");
        }

        $delimiter = null;
        $file = new SplFileObject($path);

        foreach ($file as $raw) {
            if (! is_string($raw)) {
                continue;
            }

            $line = rtrim($raw, "\r\n");

            if (trim($line) === '') {
                continue;
            }

            if ($delimiter === null) {
                $delimiter = str_contains($line, "\t") ? "\t" : ',';
            }

            yield [$this->row($line, $delimiter, $layout), $line];
        }
    }

    /**
     * @return array{uid: string, time: string, device: string|null, state: int, mode: int}|null
     */
    private function row(string $line, string $delimiter, string $layout): ?array
    {
        $fields = array_map('trim', explode($delimiter, $line));
        $deviceLayout = $layout === self::LAYOUT_DEVICE;
        $required = $deviceLayout ? 5 : 4;

        if (count($fields) < $required) {
            return null;
        }

        $uid = $fields[0];
        $time = $this->time($fields[1]);
        $device = $deviceLayout ? $fields[2] : null;
        $state = $this->byte($deviceLayout ? $fields[3] : $fields[2]);
        $mode = $this->byte($deviceLayout ? $fields[4] : $fields[3]);

        if ($uid === '' || $time === null || $state === null || $mode === null) {
            return null;
        }

        if ($device === '') {
            return null;
        }

        return [
            'uid' => $uid,
            'time' => $time,
            'device' => $device,
            'state' => $state,
            'mode' => $mode,
        ];
    }

    private function time(string $value): ?string
    {
        foreach (self::TIME_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value);

            if (! $parsed instanceof DateTimeImmutable) {
                continue;
            }

            if ($this->parsedCleanly()) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /**
     * `createFromFormat` can return an object for impossible dates such as
     * `2024-13-45 99:99:99` by rolling them over. Zero errors *and* zero
     * warnings is the gate that rejects that rollover. `getLastErrors()` is
     * `false` (not an empty array) when there were no errors at all.
     */
    private function parsedCleanly(): bool
    {
        $errors = DateTimeImmutable::getLastErrors();

        if ($errors === false) {
            return true;
        }

        return $errors['error_count'] === 0 && $errors['warning_count'] === 0;
    }

    private function byte(string $value): ?int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $int = (int) $value;

        if ($int > 255) {
            return null;
        }

        return $int;
    }
}
