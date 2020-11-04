<?php

namespace Hautelook\ShipmentTracking\Provider;

use Exception;

use Hautelook\ShipmentTracking\ShipmentInformation;

/**
 * @author Adrien Brault <adrien.brault@gmail.com>
 */
interface ProviderInterface
{
    /**
     * @param  string              $trackingNumber
     *
     * @throws Exception
     *
     * @return ShipmentInformation
     */
    public function track($trackingNumber);
}
