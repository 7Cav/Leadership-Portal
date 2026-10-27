<?php

use App\Docs\GuideLibrary;

// #408: the build fails on any broken guide in resources/docs (front matter, links, images), so a guide that would
// mislead a reader, or not render at all, never reaches production.

it('has no problem in any guide in resources/docs', function () {
    expect(app(GuideLibrary::class)->problems())->toBe([]);
});
