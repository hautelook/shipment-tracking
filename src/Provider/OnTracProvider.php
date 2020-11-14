<?php

namespace Hautelook\ShipmentTracking\Provider;

use DateTime;
use Exception;
use Guzzle\Http\Client;
use Guzzle\Http\ClientInterface;

use Hautelook\ShipmentTracking\Exception\TrackingProviderException;
use Hautelook\ShipmentTracking\ShipmentEvent;
use Hautelook\ShipmentTracking\ShipmentInformation;

use SimpleXMLElement;

class OnTracProvider implements ProviderInterface
{
    const RETURN_TO_SENDER_STATUS = "RS";

    /**
     * @var string
     */
    private $url;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    public function __construct($url = null, ClientInterface $httpClient = null)
    {
        $this->url = $url ?: 'https://www.shipontrac.net/OnTracWebServices/OnTracServices.svc/V1/shipments';
        $this->httpClient = $httpClient ?: new Client();
    }

    /**
     * {@inheritdoc}
     */
    public function track($trackingNumber)
    {
        try {
            $response = $this->httpClient->get(
                $this->url,
                array(),
                array(
                    'query' => array('tn' => $trackingNumber),
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                    'timeout' => self::TIMEOUT
                )
            )->send();

            return $this->parseResponse($response->getBody(true));
        } catch (TrackingProviderException $tpe) {
            throw $tpe;
        } catch (Exception $e) {
            throw TrackingProviderException::createFromException($e);
        }
    }

    /**
     * @param string $xml
     *
     * @throws Exception|TrackingProviderException
     *
     * @return ShipmentInformation
     */
    private function parseResponse($xml)
    {
        try {
            $shipmentStatusXml = new SimpleXMLElement($xml);
        } catch (Exception $e) {
            throw TrackingProviderException::createFromSimpleXMLException($e);
        }

        $packageXml = $shipmentStatusXml->xpath('//Package')[0];

        $delivered = 'true' === (string) $packageXml->Delivered;
        $events = array();
        foreach ($packageXml->xpath('./Events/Event') as $index => $eventXml) {
            $city = (string) $eventXml->City;
            $state = (string) $eventXml->State;
            $status = (string) $eventXml->Status;

            $location = null;
            if (strlen($city) > 0 && strlen($state) > 0) {
                $location = sprintf('%s, %s', $city, $state);
            }

            $shipmentEventType = null;
            if ($delivered && 0 === $index) { // events are ordered in a descending order
                $shipmentEventType = ShipmentEvent::TYPE_DELIVERED;
            } else if ($status == self::RETURN_TO_SENDER_STATUS) {
                $shipmentEventType = ShipmentEvent::TYPE_RETURNED_TO_SHIPPER;
            }

            $events[] = new ShipmentEvent(
                new DateTime((string) $eventXml->EventTime),
                (string) $eventXml->Description,
                $location,
                $shipmentEventType
            );
        }

        return new ShipmentInformation(
            $events,
            new DateTime((string) $packageXml->Exp_Del_Date)
        );
    }
}
