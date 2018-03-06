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
class FedexProvider implements ProviderInterface
{
    /**
     * @var string
     */
    private $key;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $accountNumber;

    /**
     * @var string
     */
    private $meterNumber;

    /**
     * @var string
     */
    private $url;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    public function __construct(
        $key,
        $password,
        $accountNumber,
        $meterNumber,
        $url = null,
        ClientInterface $httpClient = null
    ) {
        $this->key = $key;
        $this->password = $password;
        $this->accountNumber = $accountNumber;
        $this->meterNumber = $meterNumber;
        $this->url = $url ?: 'https://gateway.fedex.com/xml';
        $this->httpClient = $httpClient ?: new Client();
    }

    public function track($trackingNumber)
    {
        try {
            $response = $this->httpClient->post(
                $this->url,
                array(),
                $this->createRequestXML($trackingNumber)
            )->send();
        } catch (HttpException $e) {
            throw Exception::createFromHttpException($e);
        }

        return $this->parseTrackReply($response->getBody(true));
    }

    private function createRequestXML($trackingNumber)
    {
        <<<XML
<TrackRequest xmlns="http://fedex.com/ws/track/v9">
    <WebAuthenticationDetail>
        <UserCredential>
            <Key></Key>
            <Password></Password>
        </UserCredential>
    </WebAuthenticationDetail>
    <ClientDetail>
        <AccountNumber></AccountNumber>
        <MeterNumber></MeterNumber>
    </ClientDetail>
    <Version>
        <ServiceId></ServiceId>
        <Major></Major>
        <Intermediate></Intermediate>
        <Minor></Minor>
    </Version>
    <SelectionDetail>
        <PackageIdentifier>
            <Type></Type>
            <Value></Value>
        </PackageIdentifier>
    </SelectionDetail>
    <ProcessingOptions></ProcessingOptions>
</TrackRequest>
XML;

        $requestXml = new \SimpleXMLElement('<TrackRequest xmlns="http://fedex.com/ws/track/v9"/>');

        $requestXml->WebAuthenticationDetail->UserCredential->Key = $this->key;
        $requestXml->WebAuthenticationDetail->UserCredential->Password = $this->password;
        $requestXml->ClientDetail->AccountNumber = $this->accountNumber;
        $requestXml->ClientDetail->MeterNumber = $this->meterNumber;
        $requestXml->Version->ServiceId = 'trck';
        $requestXml->Version->Major = '9';
        $requestXml->Version->Intermediate = '1';
        $requestXml->Version->Minor = '0';
        $requestXml->SelectionDetails->PackageIdentifier->Type = 'TRACKING_NUMBER_OR_DOORTAG';
        $requestXml->SelectionDetails->PackageIdentifier->Value = $trackingNumber;
        $requestXml->ProcessingOptions = 'INCLUDE_DETAILED_SCANS';

        return $requestXml->asXML();
    }

    private function parseTrackReply($xml)
    {
        try {
            $trackReplyXml = new \SimpleXMLElement($xml);
        } catch (\Exception $e) {
            throw Exception::createFromSimpleXMLException($e);
        }

        $trackReplyXml->registerXPathNamespace('v9', 'http://fedex.com/ws/track/v9');

        $events = array();
        foreach ($trackReplyXml->xpath('//v9:TrackDetails/v9:Events') as $eventXml) {
            $city = (string) $eventXml->Address->City;
            $state = (string) $eventXml->Address->StateOrProvinceCode;

            $location = null;
            if (strlen($city) > 0 && strlen($state) > 0) {
                $location = sprintf('%s, %s', $city, $state);
            }

            $date = new \DateTime((string) $eventXml->Timestamp);

            $shipmentEventType = null;

            $eventXmlType = (string) $eventXml->EventType;
            if ('DL' === $eventXmlType) {
                $shipmentEventType = ShipmentEvent::TYPE_DELIVERED;
            }

            $events[] = new ShipmentEvent(
                $date,
                (string) $eventXml->EventDescription,
                $location,
                $shipmentEventType
            );
        }

        $estimatedDeliveryDate = null;
        if (isset($trackReplyXml->TrackReply->TrackDetails->EstimatedDeliveryTimestamp)) {
            $estimatedDeliveryDate = new \DateTime((string) $trackReplyXml->TrackReply->TrackDetails->EstimatedDeliveryTimestamp);
        }

        return new ShipmentInformation($events, $estimatedDeliveryDate);
    }
}