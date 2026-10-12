<?php

namespace App\Sync\Loa;

use App\Models\LoaType;

/**
 * What a leave thread is, read from the machine-generated tag at the start of its title:
 * `[LOA Request] - ...`, `[ELOA Report] - ...` or `[ELOA Return] - ...`. A report starts an ELOA and a
 * return ends it, so a return is not a leave period of its own.
 */
enum LoaThreadKind: string
{
    case LoaRequest = 'loa_request';
    case EloaReport = 'eloa_report';
    case EloaReturn = 'eloa_return';
    case Unknown = 'unknown';

    public static function fromTitle(string $title): self
    {
        if (preg_match('/^\s*\[\s*(e?loa)\s+(request|report|return)\s*\]/i', $title, $match) !== 1) {
            return self::Unknown;
        }

        return match ([strtolower($match[1]), strtolower($match[2])]) {
            ['loa', 'request'] => self::LoaRequest,
            ['eloa', 'report'] => self::EloaReport,
            ['eloa', 'return'] => self::EloaReturn,
            default => self::Unknown,
        };
    }

    /** The kind of leave section this kind of thread is posted in, or null when the title is unrecognised. */
    public function section(): ?LoaType
    {
        return match ($this) {
            self::LoaRequest => LoaType::Loa,
            self::EloaReport, self::EloaReturn => LoaType::Eloa,
            self::Unknown => null,
        };
    }

    /** The `Type:` value an ELOA post carries in its body, when it carries one. */
    public function bodyType(): ?string
    {
        return match ($this) {
            self::EloaReport => 'report',
            self::EloaReturn => 'return',
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LoaRequest => '[LOA Request]',
            self::EloaReport => '[ELOA Report]',
            self::EloaReturn => '[ELOA Return]',
            self::Unknown => 'an unrecognised tag',
        };
    }
}
