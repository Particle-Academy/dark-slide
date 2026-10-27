<?php

declare(strict_types=1);

namespace DarkSlide;

use DarkSlide\Exceptions\ArchiveRefused;
use DarkSlide\Exceptions\OfficeTemplateRefused;

/**
 * `vendor/bin/dark-slide`. One command today:
 *
 *     dark-slide template:convert <input> <output> [--to=document|template] [--force]
 *
 * Exit codes: 0 converted, 1 refused (the reason is printed), 2 usage.
 *
 * @internal
 */
final class Cli
{
    private const USAGE = "Usage: dark-slide template:convert <input> <output> [--to=document|template] [--force]\n"
        ."  Converts a .potx template to its .pptx twin, or back.\n"
        ."  Without --to it flips whichever the input is.\n";

    /**
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function run(array $args, $stdout, $stderr): int
    {
        $command = array_shift($args);
        $positional = [];
        $to = null;
        $force = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--to=')) {
                $to = substr($arg, 5);
            } elseif ($arg === '--force') {
                $force = true;
            } elseif (str_starts_with($arg, '--')) {
                fwrite($stderr, "Unknown option {$arg}\n".self::USAGE);

                return 2;
            } else {
                $positional[] = $arg;
            }
        }
        if ($command !== 'template:convert' || count($positional) !== 2) {
            fwrite($stderr, self::USAGE);

            return 2;
        }
        [$input, $output] = $positional;

        if (file_exists($output) && ! $force) {
            fwrite($stderr, "Refused: {$output} already exists (pass --force to replace it).\n");

            return 1;
        }
        $real = realpath($input);
        if ($real !== false && $real === realpath($output)) {
            fwrite($stderr, "Refused: the output is the input.\n");

            return 1;
        }

        try {
            // fromPath bounds the read (size, regular file, no stream wrappers).
            $converted = Agent::convertTemplate(Archive\SourceArchive::fromPath($input)->toBytes(), $to);
        } catch (OfficeTemplateRefused|ArchiveRefused $error) {
            fwrite($stderr, "Refused ({$error->reason}): {$error->getMessage()}\n");

            return 1;
        }

        // Written beside the target and renamed, so a failure leaves no half file.
        $temp = $output.'.tmp-'.bin2hex(random_bytes(4));
        if (file_put_contents($temp, $converted) !== strlen($converted) || ! rename($temp, $output)) {
            @unlink($temp);
            fwrite($stderr, "Could not write {$output}.\n");

            return 1;
        }
        $kind = Archive\OfficeTemplate::kind(Archive\SourceArchive::open($converted));
        fwrite($stdout, "Wrote {$output} ({$kind}).\n");

        return 0;
    }
}
