<?php

namespace App\Repositories;

/**
 * A document {@see CounselingDocuments} has written to the private disk (#381): the path the portal chose, the name
 * to offer on download, its size in bytes and its SHA-256, which is all the audit log ever carries of the file.
 */
final readonly class StoredDocument
{
    public function __construct(
        public string $path,
        public string $name,
        public int $size,
        public string $sha256,
    ) {}
}
