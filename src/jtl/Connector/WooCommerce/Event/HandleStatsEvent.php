<?php
/**
 * @author    Sven Mäurer <sven.maeurer@jtl-software.com>
 * @copyright 2010-2013 JTL-Software GmbH
 */

namespace jtl\Connector\WooCommerce\Event;

use jtl\Connector\Result\Action;
use Symfony\Component\EventDispatcher\Event;

class HandleStatsEvent extends Event
{
    const EVENT_NAME = 'connector.handle.stats';

    /**
     * @var Action
     */
    protected $result;
    protected $controller;

    public function __construct($controller)
    {
        $this->controller = $controller;
    }

    public function getController()
    {
        return $this->controller;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function setResult($result)
    {
        $this->result = $result;
        return $this;
    }
}
