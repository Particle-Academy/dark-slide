<?php

declare(strict_types=1);

use DarkSlide\Agent;
use DarkSlide\Helpers\MarkdownInline;

/**
 * CommonMark backslash escapes in the inline tokenizer.
 *
 * A backslash before any ASCII punctuation character makes that character
 * literal and is itself removed. A backslash before anything else (a letter,
 * a digit, a space, a non-ASCII character, the end of the paragraph) is a
 * literal backslash. Inside a code span nothing is an escape.
 *
 * Before this, an escaped asterisk printed its backslash AND still acted as an
 * emphasis marker, so there was no way at all to put a literal `**` or a
 * literal backtick into a markdown text box.
 *
 * The Node and Python engines carry the same table; keep the three in step.
 */
function escRun(string $text, bool $b = false, bool $i = false, bool $code = false): array
{
    return ['text' => $text, 'b' => $b, 'i' => $i, 'code' => $code];
}

dataset('backslash escapes', [
    'escaped bold markers are literal asterisks' => ['\\*\\*not bold\\*\\*', [escRun('**not bold**')]],
    'one escaped asterisk then a bold opener' => ['\\***x**', [escRun('*'), escRun('x', b: true)]],
    'escaped backticks do not open code' => ['\\`not code\\`', [escRun('`not code`')]],
    'escaped underscores are not italic' => ['\\_not italic\\_', [escRun('_not italic_')]],
    'an escaped asterisk inside bold is literal' => ['**a \\* b**', [escRun('a * b', b: true)]],
    'a doubled backslash is one literal backslash' => ['a \\\\ b', [escRun('a \\ b')]],
    'an escaped backslash does not escape what follows' => ['\\\\*x*', [escRun('\\'), escRun('x', i: true)]],
    'a backslash before a letter is literal' => ['C:\\path\\to', [escRun('C:\\path\\to')]],
    'a backslash before a digit is literal' => ['v\\2', [escRun('v\\2')]],
    'a backslash before a space is literal' => ['a \\ b', [escRun('a \\ b')]],
    'a trailing backslash is literal' => ['end\\', [escRun('end\\')]],
    'a backslash before non-ASCII is literal' => ['\\é', [escRun('\\é')]],
    'a leading escaped hash is a literal hash' => ['\\# not a heading', [escRun('# not a heading')]],
    'every ASCII punctuation character escapes' => [
        '\\!\\"\\#\\$\\%\\&\\\'\\(\\)\\*\\+\\,\\-\\.\\/\\:\\;\\<\\=\\>\\?\\@\\[\\\\\\]\\^\\_\\`\\{\\|\\}\\~',
        [escRun('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~')],
    ],
    'a backslash inside a code span is literal' => ['`a\\*b`', [escRun('a\\*b', code: true)]],
]);

it('applies CommonMark backslash escapes', function (string $input, array $expected) {
    expect(MarkdownInline::tokenize($input))->toBe($expected);
})->with('backslash escapes');

it('writes escaped markers to the pptx as literal text with no backslash', function () {
    $deck = [
        'id' => 'esc',
        'title' => 'Escapes',
        'theme' => ['name' => 'default'],
        'slides' => [['id' => 's1', 'elements' => [[
            'id' => 'tx',
            'type' => 'text',
            'x' => 0.1, 'y' => 0.1, 'w' => 0.8, 'h' => 0.2,
            'content' => '\\*\\*not bold\\*\\* and \\`not code\\`',
            'format' => 'markdown',
        ]]]],
    ];

    $path = tempnam(sys_get_temp_dir(), 'esc') . '.pptx';
    Agent::write($deck, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
    $zip->close();
    // And the reader sees the same literal text, in one undecorated run.
    $read = Agent::read($path);
    @unlink($path);

    expect($xml)->toContain('<a:t>**not bold** and `not code`</a:t>');
    expect($xml)->not->toContain('\\');
    expect($xml)->not->toContain(' b="1"');
    expect($xml)->not->toContain(' i="1"');
    expect($xml)->not->toContain('Consolas');

    expect($read['slides'][0]['elements'][0]['content'])->toBe('**not bold** and `not code`');
});
