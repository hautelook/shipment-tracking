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
class UspsProvider implements ProviderInterface
{
    /**
     * @var string
     */
    private $userId;

    /**
     * @var string
     */
    private $url;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    public function __construct($userId, $url = null, ClientInterface $httpClient = null)
    {
        $this->userId = $userId;
        $this->url = $url ?: 'http://production.shippingapis.com/ShippingAPI.dll';
        $this->httpClient = $httpClient ?: new Client();
    }

    public function track($trackingNumber)
    {
        try {
            $response = $this->httpClient->post(
                $this->url, 
                array(), 
                array(
                    'API' => 'TrackV2',
                    'XML' => $this->createTrackRequestXml($trackingNumber)
                ),
                array(
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                    'timeout' => self::TIMEOUT
                )
            )->send();
        } catch (\Exception $e) {
            throw Exception::createFromHttpException($e);
        }

        return $this->parseTrackResponse($response->getBody(true), $trackingNumber);
    }

    private function createTrackRequestXml($trackingNumber)
    {
        <<<XML
<TrackFieldRequest USERID="">
    <Revision>1</Revision>
    <ClientIp>127.0.0.1</ClientIp>
    <SourceId>1</SourceId>
    <TrackID ID=""></TrackID>
</TrackFieldRequest>
XML;

        $xml = new \SimpleXMLElement('<TrackFieldRequest/>');
        $xml->Revision = 1;
        $xml->ClientIp = '127.0.0.1';
        $xml->SourceId = '1';
        $xml->addAttribute('USERID', $this->userId);
        $xml->addChild('TrackID')->addAttribute('ID', $trackingNumber);

        return $xml->asXML();
    }

    private function parseTrackResponse($xml, $trackingNumber)
    {
        try {
            $trackResponseXml = new \SimpleXMLElement($xml);
        } catch (\Exception $e) {
            throw Exception::createFromSimpleXMLException($e);
        }

        $trackInfoElements = $trackResponseXml->xpath(sprintf('//TrackInfo[@ID=\'%s\']', $trackingNumber));

        if (count($trackInfoElements) < 1) {
            throw new Exception('Tracking information not found in the response.');
        }

        $trackInfoXml = reset($trackInfoElements);

        $events = array();
        foreach ($trackInfoXml->xpath('./*[self::TrackDetail|self::TrackSummary]') as $trackDetailXml) {
            $city = (string) $trackDetailXml->EventCity;
            $state = (string) $trackDetailXml->EventState;
            $label = (string) $trackDetailXml->Event;
            $eventCode = (string) $trackDetailXml->EventCode;

            $location = null;
            if (strlen($city) > 0 && strlen($state) > 0) {
                $location = sprintf('%s, %s', $city, $state);
            }

            $date = new \DateTime((string) $trackDetailXml->EventDate . ' ' . (string) $trackDetailXml->EventTime);

            $shipmentEventType = null;

            if (in_array($eventCode, USPS\EventCode::getDeliveredCodes())) {
                $shipmentEventType = ShipmentEvent::TYPE_DELIVERED;
            } elseif (in_array($eventCode, USPS\EventCode::getReturnedToShipperCodes())) {
                $shipmentEventType = ShipmentEvent::TYPE_RETURNED_TO_SHIPPER;
            } elseif (in_array($eventCode, USPS\EventCode::getDeliveryAttemptCodes())) {
                $shipmentEventType = ShipmentEvent::TYPE_DELIVERY_ATTEMPTED;
            }

            $events[] = new ShipmentEvent($date, $label, $location, $shipmentEventType);
        }

        $estimatedDeliveryDate = null;
        if (isset($trackInfoXml->ExpectedDeliveryDate)) {
            $estimatedDeliveryDate = new \DateTime((string) $trackInfoXml->ExpectedDeliveryDate);
        }

        return new ShipmentInformation($events, $estimatedDeliveryDate);
    }
}