<?php

namespace Hautelook\ShipmentTracking\Provider;

use Hautelook\ShipmentTracking\Exception\Exception;
use Hautelook\ShipmentTracking\ShipmentInformation;

/**
 * @author Adrien Brault <adrien.brault@gmail.com>
 */
interface ProviderInterface
{
    const CONNECT_TIMEOUT = 3.0;
    const TIMEOUT = 5.0;
    
    /**
     * @param  string              $trackingNumber
     * @return ShipmentInformation
     * @throws Exception
     */
    public function track($trackingNumber);
}
