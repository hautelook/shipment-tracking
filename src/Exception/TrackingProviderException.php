<?php

namespace Hautelook\ShipmentTracking\Exception;

use Exception;

class TrackingProviderException extends Exception
{
    public static function createFromException(Exception $exception)
    {
        return new static('An error occurred contacting the carrier\'s api.', 0, $exception);
    }

    public static function createFromSimpleXMLException(Exception $exception)
    {
        return new static('An error occurred while trying to parse the xml response.', 0, $exception);
    }
}
