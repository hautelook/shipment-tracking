<?php

namespace Hautelook\ShipmentTracking\Provider;

use Guzzle\Http\Client;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Exception\HttpException;
use Hautelook\ShipmentTracking\Exception\Exception;
use Hautelook\ShipmentTracking\ShipmentEvent;
use Hautelook\ShipmentTracking\ShipmentInformation;

/**
 * @author Adrien Brault <adrien.brault@gmail.com>
 */
class LandmarkProvider implements ProviderInterface
{
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
        $username,
        $password,
        $url = null,
        ClientInterface $httpClient = null
    ) {
        $this->username = $username;
        $this->password = $password;
        $this->url = $url ?: 'https://mercury.landmarkglobal.com/api/api.php';
        $this->httpClient = $httpClient ?: new Client();
    }

    public function track($trackingNumber)
    {
        try {
            $response = $this->httpClient->get($this->url, array(), array(
                'query' => array(
                    'RQXML' => $this->createRequestXml($trackingNumber),
                ),
            ))->send();
        } catch (HttpException $e) {
            throw Exception::createFromHttpException($e);
        }

        return $this->parse($response->getBody(true));
    }

    private function createRequestXml($trackingNumber)
    {
        <<<XML
<TrackRequest>
    <Login>
        <Username></Username>
        <Password></Password>
    </Login>
</TrackRequest>
XML;

        $requestXml = new \SimpleXMLElement('<TrackRequest/>');
        $requestXml->Login->Username = $this->username;
        $requestXml->Login->Password = $this->password;
        $requestXml->TrackingNumber = $trackingNumber;

        return $requestXml->asXML();
    }

    private function parse($xml)
    {
        try {
            $trackResponseXml = new \SimpleXMLElement($xml);
        } catch (\Exception $e) {
            throw Exception::createFromSimpleXMLException($e);
        }

        $events = array();
        foreach ($trackResponseXml->xpath('//Events/Event') as $eventXml) {
            $location = null;
            if ($eventXml->Location->count() > 0) {
                $location = (string) $eventXml->Location;
            }
            $status = (string) $eventXml->Status;
            $date = new \DateTime((string) $eventXml->DateTime);

            $shipmentEventType = null;

            if ('Item successfully delivered' === $status ||
                'Delivered' === $status) {
                $shipmentEventType = ShipmentEvent::TYPE_DELIVERED;
            }

            $events[] = new ShipmentEvent($date, $status, $location, $shipmentEventType);
        }

        $estimatedDeliveryDate = null;
        if (isset($trackResponseXml->Result->Packages->Package->ExpectedDelivery)) {
            $estimatedDeliveryDate = new \DateTime((string) $trackResponseXml->Result->Packages->Package->ExpectedDelivery);
        }

        return new ShipmentInformation($events, $estimatedDeliveryDate);
    }
}