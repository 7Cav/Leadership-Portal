<?php

namespace App\Integration\DriveDownload;

/**
 * Why a Google Drive link could not be brought in as a PDF (#565). Carried on {@see DriveDownloadFailed}, so the
 * counseling import picks its row's reason from a discriminator rather than by matching message text.
 */
enum DriveDownloadFailure: string
{
    /** Not a link to one file on drive.google.com or docs.google.com. */
    case NotDriveLink = 'not_drive_link';

    /** A link to a Drive folder: the import takes one file per row. */
    case Folder = 'folder';

    /**
     * Drive did not hand out the file as a PDF to someone without an account: it asked for a sign-in, answered a web
     * page or another kind of file, refused it, or redirected somewhere that is not Google's own download host.
     */
    case NotPublic = 'not_public';

    /** Drive has no file with this id: deleted, or the link is wrong. */
    case NotFound = 'not_found';

    /** The file is larger than the import accepts. */
    case TooLarge = 'too_large';

    /** Drive could not be reached, timed out, or answered with a server error or a rate limit. */
    case Unreachable = 'unreachable';
}
