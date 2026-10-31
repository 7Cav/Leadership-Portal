<?php

namespace App\Models;

/**
 * Where a counseling record's content came from (#565). Backed so the DB enum column and this type stay in lockstep.
 *
 *  - **Portal**: written in the portal, field by field. Its document is the official form, filled on request from the
 *    record; no file is kept.
 *  - **Upload**: filed before #565 with a signed PDF, which stays its source document and is what downloads.
 *  - **Import**: brought in by the counseling import from a Drive link or a PDF, kept the same way.
 */
enum CounselingSource: string
{
    case Portal = 'portal';
    case Upload = 'upload';
    case Import = 'import';
}
