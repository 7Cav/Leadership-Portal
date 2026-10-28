<?php

namespace App\Authorization;

/**
 * What a view-as target names (Story 1.13): a unit in the viewer's own scope ("the leader of
 * 2/1/A/1-7"), or — Admins only — a specific portal user, previewed with that user's own scope,
 * or a trooper on the roster who has no portal account yet, previewed with the scope their billet
 * would give them (`id` is then a `troopers` id).
 */
enum ViewAsKind: string
{
    case Unit = 'unit';
    case User = 'user';
    case Trooper = 'trooper';
}
