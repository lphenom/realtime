<?php
declare(strict_types=1);

namespace LPhenom\Realtime\Exception;

/**
 * Base exception for lphenom/realtime package.
 *
 * KPHP-compatible: extends RuntimeException directly (no reflection, no eval).
 *
 * @lphenom-build shared,kphp
 */
class RealtimeException extends \RuntimeException
{
}
