<?php

namespace App\Services\Documents;

use InvalidArgumentException;

class StoredZipArchiveService
{
    /**
     * @param  array<int, array{name: string, contents: string}>  $files
     */
    public function build(array $files): string
    {
        if ($files === []) {
            throw new InvalidArgumentException('At least one file is required.');
        }

        $localFileRecords = '';
        $centralDirectory = '';
        $offset = 0;
        [$dosTime, $dosDate] = $this->dosTimestamp();

        foreach ($files as $file) {
            $name = $this->sanitizeEntryName($file['name'] ?? '');
            $contents = $file['contents'] ?? '';
            $size = strlen($contents);
            $crc32 = hexdec(hash('crc32b', $contents));
            $nameLength = strlen($name);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc32,
                $size,
                $size,
                $nameLength,
                0,
            ).$name;

            $localFileRecords .= $localHeader.$contents;

            $centralDirectory .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc32,
                $size,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset,
            ).$name;

            $offset += strlen($localHeader) + $size;
        }

        $centralDirectoryOffset = strlen($localFileRecords);
        $centralDirectorySize = strlen($centralDirectory);
        $fileCount = count($files);

        $endOfCentralDirectory = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $fileCount,
            $fileCount,
            $centralDirectorySize,
            $centralDirectoryOffset,
            0,
        );

        return $localFileRecords.$centralDirectory.$endOfCentralDirectory;
    }

    private function sanitizeEntryName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');

        if ($name === '' || str_contains($name, '..')) {
            throw new InvalidArgumentException('ZIP entry names must be relative paths.');
        }

        return $name;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dosTimestamp(): array
    {
        $now = getdate();

        $time = ((int) $now['hours'] << 11)
            | ((int) $now['minutes'] << 5)
            | ((int) floor((int) $now['seconds'] / 2));
        $date = (((int) $now['year'] - 1980) << 9)
            | ((int) $now['mon'] << 5)
            | (int) $now['mday'];

        return [$time, $date];
    }
}
