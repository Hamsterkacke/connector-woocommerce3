<?php
/**
 * @author    Sven Mäurer <sven.maeurer@jtl-software.com>
 * @copyright 2010-2013 JTL-Software GmbH
 */

namespace jtl\Connector\WooCommerce\Controller;

use jtl\Connector\Model\Identity;
use jtl\Connector\Model\Payment as PaymentModel;
use jtl\Connector\WooCommerce\Controller\Traits\PullTrait;
use jtl\Connector\WooCommerce\Controller\Traits\PushTrait;
use jtl\Connector\WooCommerce\Controller\Traits\StatsTrait;
use jtl\Connector\WooCommerce\Utility\SQL;
use jtl\Connector\WooCommerce\Utility\Util;

class Payment extends BaseController
{
    use PullTrait, PushTrait, StatsTrait;

    public function pullData($limit)
    {
        $payments = [];

        $includeCompletedOrders = \get_option(\JtlConnectorAdmin::OPTIONS_COMPLETED_ORDERS, 'yes') === 'yes';

        $completedOrders = $this->database->queryList(SQL::paymentCompletedPull($limit, $includeCompletedOrders));

        foreach ($completedOrders as $orderId) {
            $order = \wc_get_order((int)$orderId);

            if (!$order instanceof \WC_Order) {
                continue;
            }

            $payments[] = (new PaymentModel())
                ->setId(new Identity($order->get_id()))
                ->setCustomerOrderId(new Identity($order->get_id()))
                ->setTotalSum((float)$order->get_total())
                ->setPaymentModuleCode(Util::getInstance()->mapPaymentModuleCode($order))
                ->setTransactionId($order->get_transaction_id())
                ->setCreationDate($order->get_date_paid() ? $order->get_date_paid() : $order->get_date_completed());
        }

        return $payments;
    }

    protected function pushData(PaymentModel $data)
    {
        $order = \wc_get_order((int)$data->getCustomerOrderId()->getEndpoint());

        if (!$order instanceof \WC_Order) {
            return $data;
        }

        $order->set_transaction_id($data->getTransactionId());
        $order->set_date_paid($data->getCreationDate());
        $order->save();

        return $data;
    }

    protected function getStats()
    {
        $includeCompletedOrders = \get_option(\JtlConnectorAdmin::OPTIONS_COMPLETED_ORDERS, 'yes') === 'yes';

        return (int)$this->database->queryOne(SQL::paymentCompletedPull(null, $includeCompletedOrders));
    }
}
