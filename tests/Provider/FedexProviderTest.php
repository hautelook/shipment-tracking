<?php

namespace Hautelook\ShipmentTracking\Tests\Provider;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Hautelook\ShipmentTracking\Provider\FedexProvider;
use Hautelook\ShipmentTracking\ShipmentInformation;

class FedexProviderTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $clientProphecy = $this->prophesize(ClientInterface::class);
        $requestProphecy = $this->prophesize(RequestInterface::class);
        $responseProphecy = $this->prophesize(Response::class);

        $xml = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:v9="http://fedex.com/ws/track/v9">
    <soapenv:Body>
        <TrackRequest xmlns="http://fedex.com/ws/track/v9">
            <WebAuthenticationDetail>
                <UserCredential>
                    <Key>key</Key>
                    <Password>password</Password>
                </UserCredential>
            </WebAuthenticationDetail>
            <ClientDetail>
                <AccountNumber>accountNumber</AccountNumber>
                <MeterNumber>meterNumber</MeterNumber>
            </ClientDetail>
            <Version>
                <ServiceId>trck</ServiceId>
                <Major>9</Major>
                <Intermediate>1</Intermediate>
                <Minor>0</Minor>
            </Version>
            <SelectionDetails>
                <PackageIdentifier>
                    <Type>TRACKING_NUMBER_OR_DOORTAG</Type>
                    <Value>ABC</Value>
                </PackageIdentifier>
            </SelectionDetails>
            <ProcessingOptions>INCLUDE_DETAILED_SCANS</ProcessingOptions>
        </TrackRequest>
    </soapenv:Body>
</soapenv:Envelope>
XML;
        $xml = preg_replace('/\n\s*/', '', $xml);

        $clientProphecy
            ->post(
                'https://ws.fedex.com:443/web-services',
                ['Content-Type' => 'text/xml'],
                $xml
            )
            ->willReturn($requestProphecy)
        ;
        $requestProphecy->send()->willReturn($responseProphecy);

        $responseProphecy->getBody(true)->willReturn(file_get_contents(__DIR__ . '/../fixtures/fedex.xml'));

        $provider = new FedexProvider(
            'key',
            'password',
            'accountNumber',
            'meterNumber',
            null,
            $clientProphecy->reveal()
        );
        $shipmentInformation = $provider->track('ABC');

        $this->assertInstanceOf(ShipmentInformation::class, $shipmentInformation);
        $this->assertEquals(new \DateTime('2015-04-17T15:08:53-07:00'), $shipmentInformation->getDeliveredAt());
        $this->assertSame(null, $shipmentInformation->getEstimatedDeliveryDate());

        $events = $shipmentInformation->getEvents();
        $this->assertCount(9, $events);
        $event = $events[0];
        $this->assertEquals(new \DateTime('2015-04-17T15:08:53-07:00'), $event->getDate());
        $this->assertEquals('Delivered', $event->getLabel());
        $this->assertEquals('Richland, WA', $event->getLocation());
    }
}
