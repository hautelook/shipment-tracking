<?php

namespace Hautelook\ShipmentTracking\Tests\Provider;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Hautelook\ShipmentTracking\Provider\OnTracProvider;
use Hautelook\ShipmentTracking\ShipmentInformation;
use Hautelook\ShipmentTracking\Provider\ProviderInterface;

class OnTracProviderTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $clientProphecy = $this->prophesize(ClientInterface::class);
        $requestProphecy = $this->prophesize(RequestInterface::class);
        $responseProphecy = $this->prophesize(Response::class);

        $clientProphecy
            ->get(
                'https://www.shipontrac.net/OnTracWebServices/OnTracServices.svc/V1/shipments',
                [],
                [
                    'query' => ['tn' => 'ABC'],
                    'connect_timeout' => ProviderInterface::CONNECT_TIMEOUT,
                    'timeout' => ProviderInterface::TIMEOUT
                ]
            )
            ->willReturn($requestProphecy)
        ;
        $requestProphecy->send()->willReturn($responseProphecy);

        $responseProphecy->getBody(true)->willReturn(file_get_contents(__DIR__ . '/../fixtures/ontrac.xml'));

        $provider = new OnTracProvider(null, $clientProphecy->reveal());
        $shipmentInformation = $provider->track('ABC');

        $this->assertInstanceOf(ShipmentInformation::class, $shipmentInformation);
        $this->assertEquals(new \DateTime('2015-04-17T12:09:45'), $shipmentInformation->getDeliveredAt());
        $this->assertEquals(new \DateTime('2015-04-17T12:10:00'), $shipmentInformation->getEstimatedDeliveryDate());

        $events = $shipmentInformation->getEvents();
        $this->assertCount(6, $events);
        $event = $events[0];
        $this->assertEquals(new \DateTime('2015-04-17T12:09:45'), $event->getDate());
        $this->assertEquals('DELIVERED', $event->getLabel());
        $this->assertEquals('SOUTH SAN FRANCISCO, CA', $event->getLocation());
    }
}
