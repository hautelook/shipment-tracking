<?php

namespace Hautelook\ShipmentTracking\Tests\Provider;

use DateTime;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;

use Hautelook\ShipmentTracking\Provider\LandmarkProvider;
use Hautelook\ShipmentTracking\Provider\ProviderInterface;
use Hautelook\ShipmentTracking\ShipmentInformation;

class LandmarkProviderTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $clientProphecy = $this->prophesize(ClientInterface::class);
        $requestProphecy = $this->prophesize(RequestInterface::class);
        $responseProphecy = $this->prophesize(Response::class);

        $xml = <<<XML
<TrackRequest>
    <Login>
        <Username>username</Username>
        <Password>password</Password>
    </Login>
    <TrackingNumber>ABC</TrackingNumber>
</TrackRequest>
XML;
        $xml = preg_replace('/\n\s*/', '', $xml);
        $xml = '<?xml version="1.0"?>' . "\n" . $xml . "\n";

        $clientProphecy
            ->get(
                'https://api.landmarkglobal.com/v2/Track.php',
                [],
                [
                    'query' => ['RQXML' => $xml],
                    'connect_timeout' => ProviderInterface::CONNECT_TIMEOUT,
                    'timeout' => ProviderInterface::TIMEOUT
                ]
            )
            ->willReturn($requestProphecy)
        ;
        $requestProphecy->send()->willReturn($responseProphecy);

        $responseProphecy->getBody(true)->willReturn(file_get_contents(__DIR__ . '/../fixtures/landmark.xml'));

        $provider = new LandmarkProvider('username', 'password', null, $clientProphecy->reveal());
        $shipmentInformation = $provider->track('ABC');

        $this->assertInstanceOf(ShipmentInformation::class, $shipmentInformation);
        $this->assertEquals(new DateTime('2015-04-20 15:10:10'), $shipmentInformation->getDeliveredAt());
        $this->assertSame(null, $shipmentInformation->getEstimatedDeliveryDate());

        $events = $shipmentInformation->getEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertEquals(new DateTime('2015-04-20 15:10:10'), $event->getDate());
        $this->assertEquals('Item successfully delivered', $event->getLabel());
        $this->assertEquals('Toronto, ON', $event->getLocation());
    }
}
