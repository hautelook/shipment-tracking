<?php

namespace Hautelook\ShipmentTracking\Provider;

use Hautelook\ShipmentTracking\Exception\TrackingProviderException;
use Hautelook\ShipmentTracking\ShipmentInformation;

/**
 * @author Adrien Brault <adrien.brault@gmail.com>
 */
interface ProviderInterface
{
    /**
     * @param  string              $trackingNumber
     *
     * @throws TrackingProviderException
     *
     * @return ShipmentInformation
     */
    public function track($trackingNumber);
}
