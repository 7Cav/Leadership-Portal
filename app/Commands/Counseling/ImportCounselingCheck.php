<?php

namespace App\Commands\Counseling;

/**
 * The Admin screen's check, the counseling import's first step (#565): keep the uploaded CSV and PDFs as a new import
 * batch and run the dry run on them. Handled by {@see ImportCounselingCheckHandler}.
 */
final readonly class ImportCounselingCheck
{
    /**
     * @param  array<string, string>  $uploads  each uploaded PDF, client name => temporary path
     * @param  array<string, mixed>  $fields  what the browser read from each PDF, by client name
     */
    public function __construct(
        public string $csvName,
        public string $csv,
        public array $uploads,
        public array $fields,
    ) {}
}
