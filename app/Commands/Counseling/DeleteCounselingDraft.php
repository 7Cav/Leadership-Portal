<?php

namespace App\Commands\Counseling;

/**
 * Delete a draft DCS or LOR (#565). Handled by {@see DeleteCounselingDraftHandler}.
 */
final readonly class DeleteCounselingDraft
{
    public function __construct(public int $recordId) {}
}
