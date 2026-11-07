<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The `wsparcie` report of an applied verdict could not be written: the verdict stands, the
 * signal's claim was given back ({@see Applier::verdict}, {@see Applier::late}), and the
 * webhook answers `500`, so that the gateway's redelivery raises the report.
 *
 * It never carries the error it replaces, whose message may hold the post's text or a
 * secret. No PHP 8 syntax.
 */
final class SignalError extends \RuntimeException
{
}
