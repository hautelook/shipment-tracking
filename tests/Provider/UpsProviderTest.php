<?php

namespace Hautelook\ShipmentTracking\Tests\Provider;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Hautelook\ShipmentTracking\Provider\UpsProvider;
use Hautelook\ShipmentTracking\ShipmentInformation;
use Hautelook\ShipmentTracking\Provider\ProviderInterface;

class UpsProviderTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $clientProphecy = $this->prophesize(ClientInterface::class);
        $requestProphecy = $this->prophesize(RequestInterface::class);
        $responseProphecy = $this->prophesize(Response::class);

        $authXml = <<<XML
<AccessRequest>
    <AccessLicenseNumber>accessLicenseNumber</AccessLicenseNumber>
    <UserId>username</UserId>
    <Password>password</Password>
</AccessRequest>
XML;
        $authXml = preg_replace('/\n\s*/', '', $authXml);
        $authXml = '<?xml version="1.0"?>' . "\n" . $authXml . "\n";
        $trackXml = <<<XML
<TrackRequest>
    <Request>
        <RequestAction>Track</RequestAction>
        <RequestOption>1</RequestOption>
    </Request>
    <TrackingNumber>ABC</TrackingNumber>
</TrackRequest>
XML;
        $trackXml = preg_replace('/\n\s*/', '', $trackXml);
        $trackXml = '<?xml version="1.0"?>' . "\n" . $trackXml . "\n";

        $clientProphecy
            ->post(
                'https://onlinetools.ups.com/ups.app/xml/Track',
                [],
                $authXml . $trackXml,
                array(
                    'connect_timeout' => ProviderInterface::CONNECT_TIMEOUT,
                    'timeout' => ProviderInterface::TIMEOUT
                )
            )
            ->willReturn($requestProphecy)
        ;
        $requestProphecy->send()->willReturn($responseProphecy);

        $responseProphecy->getBody(true)->willReturn(file_get_contents(__DIR__ . '/../fixtures/ups.xml'));

        $provider = new UpsProvider(
            'accessLicenseNumber',
            'username',
            'password',
            null,
            $clientProphecy->reveal()
        );
        $shipmentInformation = $provider->track('ABC');

        $this->assertInstanceOf(ShipmentInformation::class, $shipmentInformation);
        $this->assertEquals(
            \DateTime::createFromFormat('YmdHis', '20150417150700'),
            $shipmentInformation->getDeliveredAt()
        );
        $this->assertSame(null, $shipmentInformation->getEstimatedDeliveryDate());

        $events = $shipmentInformation->getEvents();
        $this->assertCount(24, $events);
        $event = $events[0];
        $this->assertEquals(\DateTime::createFromFormat('YmdHis', '20150417150700'), $event->getDate());
        $this->assertEquals('DELIVERED', $event->getLabel());
        $this->assertEquals('WALHALLA, ND', $event->getLocation());
    }
}
