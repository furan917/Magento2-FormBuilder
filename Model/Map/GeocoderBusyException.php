<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Map;

/** The site-wide upstream slot is taken; the caller may retry shortly. */
class GeocoderBusyException extends \RuntimeException
{
}
