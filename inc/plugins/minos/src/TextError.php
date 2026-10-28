<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * A regular expression of {@see Text} failed (PCRE's backtrack or recursion limit, an
 * internal error): the post's text could not be read, so nothing may be sent in its place.
 *
 * It never carries the text, only PCRE's error number. No PHP 8 syntax.
 */
final class TextError extends \RuntimeException
{
}
