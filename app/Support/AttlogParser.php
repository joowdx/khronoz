<?php

namespace App\Support;

use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use SplFileObject;

final class AttlogParser
{
    public const LAYOUT_STANDARD = 'standard';

    public const LAYOUT_DEVICE = 'device';

    /** @var list<string> */
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
