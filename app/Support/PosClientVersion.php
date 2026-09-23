<?php

namespace App\Support;

/**
 * Branch POS shell/asset version. Heartbeat compares this to the page that
 * is currently running so a stale tab can be told to refresh.
 */
class PosClientVersion
{
    public const ASSET = '6.10';
}
