<?php

namespace Hautelook\ShipmentTracking\Provider;

use Guzzle\Http\Client;
use Guzzle\Http\ClientInterface;
use Hautelook\ShipmentTracking\Exception\Exception;
use Hautelook\ShipmentTracking\ShipmentEvent;
use Hautelook\ShipmentTracking\ShipmentInformation;

/**
 * @author Adrien Brault <adrien.brault@gmail.com>
 */
class UpsProvider implements ProviderInterface
{
    /**
     * @var string
     */
    private $accessLicenseNumber;

    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $url;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    public function __construct(
        $accessLicenseNumber,
        $username,
        $password,
        $url = null,
        ClientInterface $httpClient = null
    ) {
        $this->accessLicenseNumber = $accessLicenseNumber;
        $this->username = $username;
        $this->password = $password;
        $this->url = $url ?: 'https://onlinetools.ups.com/ups.app/xml/Track';
        $this->httpClient = $httpClient ?: new Client();
    }

    public function track($trackingNumber)
    {
        $body = $this->createAuthenticationXml() . $this->createTrackXml($trackingNumber);

        try {
            $response = $this->httpClient->post(
                $this->url, 
                array(), 
                $body,
                array(
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                    'timeout' => self::TIMEOUT
                )
            )->send();
        } catch (\Exception $e) {
            throw Exception::createFromHttpException($e);
        }

        return $this->parse($response->getBody(true));
    }

    private function createAuthenticationXml()
    {
        <<<XML
<AccessRequest>
    <AccessLicenseNumber></AccessLicenseNumber>
    <UserId></UserId>
    <Password></Password>
</AccessRequest>
XML;

        $authenticationXml = new \SimpleXMLElement('<AccessRequest/>');
        $authenticationXml->AccessLicenseNumber = $this->accessLicenseNumber;
        $authenticationXml->UserId = $this->username;
        $authenticationXml->Password = $this->password;

        return $authenticationXml->asXML();
    }

    private function createTrackXml($trackingNumber)
    {
        <<<XML
<TrackRequest>
    <Request>
        <RequestAction>Track</RequestAction>
        <RequestOption>1</RequestOption>
    </Request>
    <TrackingNumber></TrackingNumber>
</TrackRequest>
XML;

        $trackXml = new \SimpleXMLElement('<TrackRequest/>');
        $trackXml->Request->RequestAction = 'Track';
        $trackXml->Request->RequestOption = '1';
        $trackXml->TrackingNumber = $trackingNumber;

        return $trackXml->asXML();
    }

    private function parse($xml)
    {
        try {
            $trackResponseXml = new \SimpleXMLElement(utf8_encode($xml));
        } catch (\Exception $e) {
            throw Exception::createFromSimpleXMLException($e);
        }

        if ('Failure' === (string) $trackResponseXml->Response->ResponseStatusDescription) {
            if (null !== $trackResponseXml->Response->Error) {
                // No tracking information available
                throw new Exception((string) $trackResponseXml->Response->Error->ErrorDescription);
            }

            throw new Exception('Unknown failure');
        }

        $packageReturned = $trackResponseXml->Shipment->Package->ReturnTo->count() > 0;
        $events = array();
        foreach ($trackResponseXml->xpath('//Package/Activity') as $activityXml) {
            $city = (string) $activityXml->ActivityLocation->Address->City;
            $state = (string) $activityXml->ActivityLocation->Address->StateProvinceCode;
            $label = (string) $activityXml->Status->StatusType->Description;
            $statusCode = (string) $activityXml->Status->StatusType->Code;

            $location = null;
            if (strlen($city) > 0 && strlen($state) > 0) {
                $location = sprintf('%s, %s', $city, $state);
            }

            $date = \DateTime::createFromFormat(
                'YmdHis',
                (string) $activityXml->Date . (string) $activityXml->Time
            );

            $shipmentEventType = null;

            if ('D' === $statusCode) { // delivered
                $shipmentEventType = ShipmentEvent::TYPE_DELIVERED;
            } elseif ('X' === $statusCode) { // exception
                if (false !== stripos($label, 'DELIVERY ATTEMPT')) {
                    $shipmentEventType = ShipmentEvent::TYPE_DELIVERY_ATTEMPTED;
                }
                if ($packageReturned && false !== stripos($label, 'RETURN')) {
                    $shipmentEventType = ShipmentEvent::TYPE_RETURNED_TO_SHIPPER;
                }
            }

            $events[] = new ShipmentEvent($date, $label, $location, $shipmentEventType);
        }

        $estimatedDeliveryDate = null;
        if (isset($trackResponseXml->Shipment->ScheduledDeliveryDate)) {
            $estimatedDeliveryDate = new \DateTime((string) $trackResponseXml->Shipment->ScheduledDeliveryDate);
        }

        return new ShipmentInformation(
            $events,
            $estimatedDeliveryDate
        );
    }
}