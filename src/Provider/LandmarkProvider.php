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

class LandmarkProvider implements ProviderInterface
{
    const DELIVERED_STATUSES = array(
        "Item successfully delivered",
        "Delivered",
        "Delivered to your community mailbox, parcel locker or apt./condo mailbox"
    );

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
        $this->url = $url ?: 'https://api.landmarkglobal.com/v2/Track.php';
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
                    'query' => array('RQXML' => $this->createRequestXml($trackingNumber)),
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                    'timeout' => self::TIMEOUT
                )
            )->send();

            return $this->parse($response->getBody(true));
        } catch (TrackingProviderException $tpe) {
            throw $tpe;
        } catch (Exception $e) {
            throw TrackingProviderException::createFromException($e);
        }
    }

    /**
     * @param string $trackingNumber
     *
     * @return mixed
     */
    private function createRequestXml($trackingNumber)
    {
        // XML Structure provided for reference.
        <<<XML
<TrackRequest>
    <Login>
        <Username></Username>
        <Password></Password>
    </Login>
</TrackRequest>
XML;

        $requestXml = new SimpleXMLElement('<TrackRequest/>');
        $requestXml->Login->Username = $this->username;
        $requestXml->Login->Password = $this->password;
        $requestXml->TrackingNumber = $trackingNumber;

        return $requestXml->asXML();
    }

    /**
     * @param string $xml
     *
     * @throws Exception|TrackingProviderException
     *
     * @return ShipmentInformation
     */
    private function parse($xml)
    {
        try {
            $trackResponseXml = new SimpleXMLElement($xml);
        } catch (Exception $e) {
            throw TrackingProviderException::createFromSimpleXMLException($e);
        }

        $events = array();
        foreach ($trackResponseXml->xpath('//Events/Event') as $eventXml) {
            $location = null;
            if ($eventXml->Location->count() > 0) {
                $location = (string) $eventXml->Location;
            }
            $status = (string) $eventXml->Status;
            $date = new DateTime((string) $eventXml->DateTime);

            $shipmentEventType = null;

            if (in_array($status, self::DELIVERED_STATUSES)) {
                $shipmentEventType = ShipmentEvent::TYPE_DELIVERED;
            }

            $events[] = new ShipmentEvent($date, $status, $location, $shipmentEventType);
        }

        $estimatedDeliveryDate = null;
        if (isset($trackResponseXml->Result->Packages->Package->ExpectedDelivery)) {
            $estimatedDeliveryDate = new DateTime((string) $trackResponseXml->Result->Packages->Package->ExpectedDelivery);
        }

        return new ShipmentInformation($events, $estimatedDeliveryDate);
    }
}
