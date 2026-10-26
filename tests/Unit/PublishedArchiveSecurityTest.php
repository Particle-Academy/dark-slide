<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Reader\PptxReader;

/** A small deterministic ZIP fixture builder, independent of production readers. */
function security_source_zip(array $entries, string $comment = ''): string
{
    $locals = '';
    $central = '';
    foreach ($entries as $entry) {
        $name = $entry['name'];
        $data = $entry['data'];
        $method = $entry['method'] ?? 0;
        $descriptor = $entry['descriptor'] ?? false;
        $flags = $entry['flags'] ?? ($descriptor ? 0x0808 : 0x0800);
        $compressed = $method === 8 ? gzdeflate($data) : $data;
        $crc = $entry['crc'] ?? crc32($data);
        $size = $entry['size'] ?? strlen($data);
        $offset = strlen($locals);
        $locals .= pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, $method, 0, 33,
            $descriptor ? 0 : $crc, $descriptor ? 0 : strlen($compressed), $descriptor ? 0 : $size, strlen($name), 0).$name.$compressed;
        if ($descriptor) {
            $locals .= pack('VVVV', 0x08074B50, $crc, strlen($compressed), $size);
        }
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, $flags, $method, 0, 33,
            $crc, strlen($compressed), $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
    }

    return $locals.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($central), strlen($locals), strlen($comment)).$comment;
}

function security_read_attack(string $attack): string
{
    $entries = [
        ['name' => 'ppt/presentation.xml', 'data' => '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>'],
    ];
    $entries[] = match ($attack) {
        'bomb' => ['name' => 'unknown/bomb.bin', 'data' => str_repeat('a', 2097152), 'method' => 8],
        'duplicate' => $entries[0],
        'crc' => ['name' => 'unknown/corrupt.bin', 'data' => 'opaque', 'crc' => 123],
        'path' => ['name' => '../outside.xml', 'data' => 'opaque'],
        default => ['name' => 'unknown/valid.bin', 'data' => 'opaque'],
    };
    $bytes = security_source_zip($entries);

    return $attack === 'truncated' ? substr($bytes, 0, -4) : $bytes;
}

function security_refused(callable $action, string $reason): void
{
    $caught = null;
    try {
        $action();
    } catch (Throwable $error) {
        $caught = $error;
    }
    expect($caught)->toBeInstanceOf('\DarkSlide\Exceptions\ArchiveRefused');
    expect($caught->reason)->toBe($reason);
}

it('rejects unsafe packages at the published read boundary', function (string $attack, string $reason) {
    $path = tempnam(sys_get_temp_dir(), 'sibling_archive_');
    file_put_contents($path, security_read_attack($attack));
    try {
        security_refused(fn () => Agent::read($path), $reason);
    } finally {
        unlink($path);
    }
})->with([['bomb', 'archive_limit'], ['duplicate', 'archive_duplicate'], ['crc', 'archive_corrupt'], ['truncated', 'archive_corrupt'], ['path', 'archive_path']]);

it('accepts the valid package control', function () {
    $bytes = security_read_attack('valid');
    $doc = (new PptxReader)->fromBytes($bytes);
    expect($doc)->toHaveKey('slides');
});

it('rejects unsafe bytes at the direct reader boundary', function (string $attack, string $reason) {
    security_refused(fn () => (new PptxReader)->fromBytes(security_read_attack($attack)), $reason);
})->with([['bomb', 'archive_limit'], ['duplicate', 'archive_duplicate'], ['crc', 'archive_corrupt'], ['truncated', 'archive_corrupt'], ['path', 'archive_path']]);
