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
class FedexProvider implements ProviderInterface
{
    const DELIVERED = 'DL';
    const RETURN_TO_SHIPPER = 'RS';

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
        $this->url = $url ?: 'https://ws.fedex.com:443/web-services';
        $this->httpClient = $httpClient ?: new Client();
    }

    public function track($trackingNumber)
    {
        try {
            $response = $this->httpClient->post(
                $this->url,
                array('Content-Type' => 'text/xml'),
                $this->createRequestXML($trackingNumber),
                array(
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                    'timeout' => self::TIMEOUT
                )
            )->send();
        } catch (\Exception $e) {
            throw Exception::createFromHttpException($e);
        }
        return $this->parseTrackReply($response->getBody(true));
    }

    private function createRequestXML($trackingNumber)
    {
        <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:v9="http://fedex.com/ws/track/v9"> 
    <soapenv:Body>
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
    </soapenv:Body>
</soapenv:Envelope>
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

        $requestBody = $requestXml->asXML();

        return $this->wrapSoapRequest($requestBody);
    }

    private function wrapSoapRequest($requestBody) {
        $envelopeHeader = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:v9="http://fedex.com/ws/track/v9"><soapenv:Body>';
        $envelopeFooter = '</soapenv:Body></soapenv:Envelope>';
        $requestSoapXml = str_replace(
            ["<?xml version=\"1.0\"?>\n", "\n"],
            "",
            $envelopeHeader . $requestBody . $envelopeFooter
        );

        return $requestSoapXml;
    }

    private function parseTrackReply($xml)
    {
        try {
            $cleanXML = str_ireplace(['SOAP-ENV:', 'SOAP:'], '', $xml);
            $trackReplyXml = new \SimpleXMLElement($cleanXML);
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
            if (self::DELIVERED === $eventXmlType) {
                $shipmentEventType = ShipmentEvent::TYPE_DELIVERED;
            } else if (self::RETURN_TO_SHIPPER === $eventXmlType) {
                $shipmentEventType = ShipmentEvent::TYPE_RETURNED_TO_SHIPPER;
            }

            $events[] = new ShipmentEvent(
                $date,
                (string) $eventXml->EventDescription,
                $location,
                $shipmentEventType
            );
        }

        $estimatedDeliveryElement = $trackReplyXml->xpath('//v9:TrackDetails')[0]->EstimatedDeliveryTimestamp;
        $estimatedDeliveryDate = null;
        if (isset($estimatedDeliveryElement) && !empty($estimatedDeliveryElement)) {
            $estimatedDeliveryDate = new \DateTime((string) $estimatedDeliveryElement);
        }

        return new ShipmentInformation($events, $estimatedDeliveryDate);
    }
}