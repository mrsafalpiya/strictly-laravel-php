<?php

namespace StrictlyLaravel\Install;

/**
 * What Kit Conversion did with one Base Kit file.
 */
enum ConversionOutcome
{
    /** The file was a known upstream version and has been replaced. */
    case Converted;

    /** The file already holds the converted version. */
    case AlreadyConverted;

    /** The file's content is not a known upstream version, so it was left untouched. */
    case Unknown;

    /** The app has no such file. */
    case Missing;
}
