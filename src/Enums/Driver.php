<?php

declare(strict_types=1);

namespace Beziworld\LabelPrinting\Enums;

/**
 * Transport used to deliver raw bytes to a configured printer.
 */
enum Driver: string
{
    /** Raw TCP socket, usually port 9100 (JetDirect / AppSocket). */
    case Network = 'network';

    /** CUPS queue through the "lp" command with the "raw" option. */
    case Cups = 'cups';

    /** Shared printer on a Windows host (UNC copy on Windows, smbclient elsewhere). */
    case Windows = 'windows';

    /** Local file or device node, for example /dev/usb/lp0 or a capture file. */
    case File = 'file';

    /** Discards every job; useful in local environments. */
    case Null = 'null';
}
