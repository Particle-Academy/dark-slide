<?php

declare(strict_types=1);

/**
 * The agent guide ships, and is the same bytes in all three engines.
 *
 * Each engine pins this hash over LF-normalised content, so a one-sided edit
 * fails a build instead of telling a model one thing in PHP and another in
 * Node. Edit all three copies, then all three constants.
 */
const AGENT_GUIDE_SHA256 = '48f3697a2c9705e2c78d52948b7bf16431df0fc29d618b1e1c71ded11902e466';

it('returns the guide, and it is the shared one', function () {
    $guide = DarkSlide\Agent::guide();

    expect(hash('sha256', str_replace("\r\n", "\n", $guide)))->toBe(AGENT_GUIDE_SHA256)
        ->and($guide)->toStartWith('# Agent guide:');
});
