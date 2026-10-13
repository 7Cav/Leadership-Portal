<?php

namespace App\Models;

/**
 * Why a trooper who missed Section Practice without a makeup missed it — the reason a leader
 * picks for each unaccounted trooper before sending the weekly report (5.5), stored in
 * `weekly_reports.unaccounted`. A fixed list, so command can read and compare reasons across
 * sections rather than parse free text.
 */
enum UnaccountedReason: string
{
    case RealLifeLate = 'real_life_late';
    case NoNotice = 'no_notice';
    case Technical = 'technical';
    case Sick = 'sick';
    case MakeupBooked = 'makeup_booked';

    public function label(): string
    {
        return match ($this) {
            self::RealLifeLate => 'Real-life conflict, told leader late',
            self::NoNotice => 'No notice, follow-up booked',
            self::Technical => 'Technical problem (game, mods, connection)',
            self::Sick => 'Sick',
            self::MakeupBooked => 'Makeup booked before next SP',
        };
    }
}
