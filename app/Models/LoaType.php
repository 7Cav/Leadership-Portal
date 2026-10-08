<?php

namespace App\Models;

/**
 * The kind of leave a forum thread records. Backed so the DB enum column and this type stay in
 * lockstep. LOA threads are posted in per-battalion forum sections and ELOA threads in their own, so the
 * type comes from the section; where Reserve is posted is not settled yet (4.2).
 */
enum LoaType: string
{
    case Loa = 'loa';
    case Eloa = 'eloa';
    case Reserve = 'reserve';

    public function label(): string
    {
        return match ($this) {
            self::Loa => 'LOA',
            self::Eloa => 'ELOA',
            self::Reserve => 'Reserve',
        };
    }
}
