<?php

namespace App\Authorization;

/**
 * What a view-as target names (Story 1.13): a unit in the viewer's own scope ("the leader of
 * 2/1/A/1-7"), or — Admins only — a specific portal user, previewed with that user's own scope.
 */
enum ViewAsKind: string
{
    case Unit = 'unit';
    case User = 'user';
}
