<?php

namespace Hautelook\ShipmentTracking\Provider;

use Hautelook\ShipmentTracking\Exception\TrackingProviderException;
use Hautelook\ShipmentTracking\ShipmentInformation;

interface ProviderInterface
{
    const CONNECT_TIMEOUT = 3.0;
    const TIMEOUT = 5.0;

    /**
     * @param string $trackingNumber
     *
     * @throws TrackingProviderException
     *
     * @return ShipmentInformation
     */
    public function track($trackingNumber);
}
