<?php

declare(strict_types=1);

/**
 * The agent guide names every public Agent method, or the method is on the
 * allowlist below with the reason it is left out.
 *
 * A method the guide never names is a method no model reading the guide will
 * use. The guide was last touched at 0.19.0; this fails the build on the next
 * method that ships without a line in it.
 *
 * "Names" means written as code: `Agent::x`, `Agent.x` or a backticked `x`, so
 * the English word "read" in a sentence does not count as documenting read().
 * The Node and Python ports carry the same test over their own surfaces.
 */

/** @return array<string,string> method => why the guide does not name it */
function darkSlideGuideAllowlist(): array
{
    return [
        'guide' => 'the guide itself: a model reading it already has it',
    ];
}

/** @return list<string> */
function darkSlideGuidePublicMethods(): array
{
    $methods = (new ReflectionClass(DarkSlide\Agent::class))->getMethods(ReflectionMethod::IS_PUBLIC);

    return array_values(array_map(fn (ReflectionMethod $m): string => $m->getName(), $methods));
}

function darkSlideGuideNames(string $guide, string $method): bool
{
    $name = preg_quote($method, '/');

    return preg_match('/(Agent::|Agent\.|`)'.$name.'\b/', $guide) === 1;
}

it('names every public Agent method, or allowlists it with a reason', function () {
    $guide = DarkSlide\Agent::guide();
    $allow = darkSlideGuideAllowlist();

    $missing = array_values(array_filter(
        darkSlideGuidePublicMethods(),
        fn (string $m): bool => ! isset($allow[$m]) && ! darkSlideGuideNames($guide, $m),
    ));

    expect($missing)->toBe([], 'agent-guide/professional-decks.md never names: '.implode(', ', $missing));
});

it('allowlists only methods that exist, each with a reason, none the guide already names', function () {
    $guide = DarkSlide\Agent::guide();
    $methods = darkSlideGuidePublicMethods();

    foreach (darkSlideGuideAllowlist() as $method => $reason) {
        expect($methods)->toContain($method)
            ->and(trim($reason))->not->toBe('')
            ->and(darkSlideGuideNames($guide, $method))->toBeFalse("{$method} is named in the guide; drop it from the allowlist");
    }
});
