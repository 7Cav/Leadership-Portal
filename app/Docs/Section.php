<?php

namespace App\Docs;

/** A group of guides in the contents tree, as listed in `resources/docs/sections.yaml`. */
final readonly class Section
{
    public function __construct(
        public string $key,
        public string $title,
        public int $order,
    ) {}
}
