<?php

namespace Hautelook\ShipmentTracking\Tests\Provider;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Hautelook\ShipmentTracking\Provider\UspsProvider;
use Hautelook\ShipmentTracking\ShipmentEvent;
use Hautelook\ShipmentTracking\ShipmentInformation;
use Hautelook\ShipmentTracking\Provider\ProviderInterface;

class UspsProviderTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $clientProphecy = $this->prophesize(ClientInterface::class);
        $requestProphecy = $this->prophesize(RequestInterface::class);
        $responseProphecy = $this->prophesize(Response::class);

        $xml = <<<XML
<TrackFieldRequest USERID="userId">
    <Revision>1</Revision>
    <ClientIp>127.0.0.1</ClientIp>
    <SourceId>1</SourceId>
    <TrackID ID="9102969010383081813033"/>
</TrackFieldRequest>
XML;
        $xml = preg_replace('/\n\s*/', '', $xml);
        $xml = '<?xml version="1.0"?>' . "\n" . $xml . "\n";

        $clientProphecy
            ->post(
                'http://production.shippingapis.com/ShippingAPI.dll',
                [],
                [
                    'API' => 'TrackV2',
                    'XML' => $xml,
                ],
                array(
                    'connect_timeout' => ProviderInterface::CONNECT_TIMEOUT,
                    'timeout' => ProviderInterface::TIMEOUT
                )
            )
            ->willReturn($requestProphecy)
        ;
        $requestProphecy->send()->willReturn($responseProphecy);

        $responseProphecy->getBody(true)->willReturn(file_get_contents(__DIR__ . '/../fixtures/usps.xml'));

        $provider = new UspsProvider(
            'userId',
            null,
            $clientProphecy->reveal()
        );
        $shipmentInformation = $provider->track('9102969010383081813033');

        $this->assertInstanceOf(ShipmentInformation::class, $shipmentInformation);
        $this->assertEquals(
            new \DateTime('March 8, 2012 9:58 am'),
            $shipmentInformation->getDeliveredAt()
        );
        $this->assertEquals(new \DateTime('March 9, 2012'), $shipmentInformation->getEstimatedDeliveryDate());

        $events = $shipmentInformation->getEvents();
        $this->assertCount(9, $events);
        $event = $events[0];
        $this->assertEquals(new \DateTime('March 8, 2012 9:58 am'), $event->getDate());
        $this->assertEquals('Delivered', $event->getLabel());
        $this->assertEquals(ShipmentEvent::TYPE_DELIVERED, $event->getType());
        $this->assertEquals('BEVERLY HILLS, CA', $event->getLocation());
    }
}
