<?php
/**
* BoxBilling
*
* @copyright BoxBilling, Inc (http://www.boxbilling.com)
* @license   Apache-2.0
*
* Copyright BoxBilling, Inc
* This source file is subject to the Apache-2.0 License that is bundled
* with this source code in the file LICENSE
*/

namespace Box\Mod\Invoice;
use Box\InjectionAwareInterface;

class ServiceInvoiceItem implements InjectionAwareInterface
{
    /**
     * @var \Box_Di
     */
    protected $di = null;

    /**
     * @param \Box_Di $di
     */
    public function setDi($di)
    {
        $this->di = $di;
    }

    /**
     * @return \Box_Di
     */
    public function getDi()
    {
        return $this->di;
    }

    public function markAsPaid(\Model_InvoiceItem $item, $charge = TRUE)
    {
        if($charge && !$item->charged) {
            $this->creditInvoiceItem($item);
            $item->charged        = TRUE;
            $item->updated_at     = date('c');
        }

        $item->status         = \Model_InvoiceItem::STATUS_PENDING_SETUP;
        $item->updated_at     = date('c');
        $this->di['db']->store($item);

        $oid = $this->getOrderId($item);
        if(NULL !== $oid) {
            $orderService = $this->di['mod_service']('Order');
            $order = $this->di['db']->load('ClientOrder', $oid);
            if($order instanceof \Model_ClientOrder) {
                $orderService->unsetUnpaidInvoice($order);
            }
        }
    }

    public function executeTask(\Model_InvoiceItem $item)
    {
        if($item->status == \Model_InvoiceItem::STATUS_EXECUTED) {
            return true;
        }

        if($item->type == \Model_InvoiceItem::TYPE_ORDER) {
            $order_id = $this->getOrderId($item);
            $order = $this->di['db']->load('ClientOrder', $order_id);
            if(!$order instanceof \Model_ClientOrder) {
                throw new \Box_Exception('Could not activate proforma item. Order :id not found', array(':id'=>$order_id));
            }
            $orderService = $this->di['mod_service']('Order');
            switch ($item->task) {
                case \Model_InvoiceItem::TASK_ACTIVATE:
                    $product = $this->di['db']->findOne('Product', $order->product_id);
                    if($product->setup == \Model_Product::SETUP_AFTER_PAYMENT) {
                        try {
                            $orderService->activateOrder($order);
                        } catch(\Exception $e) {
                            error_log($e->getMessage());
                            $orderService->saveStatusChange($order, 'Order could not be activated due to error: '.$e->getMessage());
                        }
                    }
                    break;

                case \Model_InvoiceItem::TASK_RENEW:
                    try {
                        $order = $this->di['db']->load('ClientOrder', $order_id);
                        $orderService->renewOrder($order);
                    } catch(\Exception $e) {
                        error_log($e->getMessage());
                        $orderService->saveStatusChange($order, 'Order could not renew due to error: '.$e->getMessage());
                    }
                    break;

                default:
                    // do nothing for unregistered tasks
                    break;
            }

            $this->markAsExecuted($item);
        }

        if($item->type == \Model_InvoiceItem::TYPE_HOOK_CALL) {
            try {
                $params = json_decode($item->rel_id);
                $this->di['events_manager']->fire(array('event'=>$item->task, 'params'=>$params));
            } catch(\Exception $e) {
                error_log($e->getMessage());
            }
            $this->markAsExecuted($item);
        }

        if($item->type == \Model_InvoiceItem::TYPE_DEPOSIT) {
            //@todo - do nothing on deposit transaction
            $this->markAsExecuted($item);
        }

        if($item->type == \Model_InvoiceItem::TYPE_CUSTOM) {
            //@todo ?
            $this->markAsExecuted($item);
        }
    }

    public function addNew(\Model_Invoice $proforma, array $data)
    {
        if(!isset($data['title']) || empty($data['title'])) {
            throw new \Box_Exception('Invoice item title is missing');
        }

        $period = isset($data['period']) ? $data['period'] : NULL;
        if($period) {
            $periodCheck = $this->di['period']($period);
        }

        $type = isset($data['type']) ? $data['type'] : \Model_InvoiceItem::TYPE_CUSTOM;
        $rel_id = isset($data['rel_id']) ? $data['rel_id'] : NULL;
        $task = isset($data['task']) ? $data['task'] : \Model_InvoiceItem::TASK_VOID;
        $status = isset($data['status']) ? $data['status'] : \Model_InvoiceItem::STATUS_PENDING_PAYMENT;

        $pi = $this->di['db']->dispense('InvoiceItem');
        $pi->invoice_id     = $proforma->id;
        $pi->type           = $type;
        $pi->rel_id         = $rel_id;
        $pi->task           = $task;
        $pi->status         = $status;
        $pi->title          = $data['title'];
        $pi->period         = $period;
        $pi->quantity       = isset($data['quantity']) ? (int)$data['quantity'] : 1;
        $pi->unit           = isset($data['unit']) ? (string)$data['unit'] : NULL;
        $pi->charged        = isset($data['charged']) ? (bool)$data['charged'] : 0;
        $pi->price          = isset($data['price']) ? (float)$data['price'] : 0;
        $pi->taxed          = isset($data['taxed']) ? (bool)$data['taxed'] : FALSE;
        $pi->created_at     = date('c');
        $pi->updated_at     = date('c');
        $itemId = $this->di['db']->store($pi);

        return (int) $itemId;
    }

    public function getTotal(\Model_InvoiceItem $item)
    {
        return floatval($item->price * $item->quantity);
    }

    public function getTax(\Model_InvoiceItem $item)
    {
        if(!$item->taxed) {
            return 0;
        }

        $rate = $this->di['db']->getCell('SELECT taxrate FROM invoice WHERE id = :id', array('id'=>$item->invoice_id));
        if($rate <= 0) {
            return 0;
        }

        return round(($item->price * $rate / 100), 2);
    }

    public function update(\Model_InvoiceItem $item, array $data)
    {
        if(isset($data['title'])) {
            $item->title = $data['title'];
        }

        if(isset($data['price'])) {
            $item->price = $data['price'];
        }

        if(isset($data['taxed']) && !empty($data['taxed'])) {
            $item->taxed = (bool)$data['taxed'];
        } else {
            $item->taxed = false;
        }

        $item->updated_at = date('c');
        $this->di['db']->store($item);
    }


    public function remove(\Model_InvoiceItem $model)
    {
        $id = $model->id;
        $this->di['db']->trash($model);
        $this->di['logger']->info('Removed invoice item "%s"', $id);
        return true;
    }

    public function generateForAddFunds(\Model_Invoice $proforma, $amount)
    {
        $pi = $this->di['db']->dispense('InvoiceItem');
        $pi->invoice_id     = $proforma->id;
        $pi->type           = \Model_InvoiceItem::TYPE_DEPOSIT;
        $pi->rel_id         = NULL;
        $pi->task           = \Model_InvoiceItem::TASK_VOID;
        $pi->status         = \Model_InvoiceItem::STATUS_PENDING_PAYMENT;
        $pi->title          = __('Add funds to account');
        $pi->period         = NULL;
        $pi->quantity       = 1;
        $pi->unit           = NULL;
        $pi->charged        = 1;
        $pi->price          = $amount;
        $pi->taxed          = FALSE;
        $pi->created_at     = date('c');
        $pi->updated_at     = date('c');
        $this->di['db']->store($pi);
    }

    public function creditInvoiceItem(\Model_InvoiceItem $item)
    {
        $total = $this->getTotalWithTax($item);
        if($total <= 0) {
            return TRUE;
        }

        $invoice = $this->di['db']->load('Invoice', $item->invoice_id);
        $client = $this->di['db']->load('Client', $invoice->client_id);

        $credit = $this->di['db']->dispense('ClientBalance');
        $credit->client_id = $client->id;
        $credit->type = 'invoice';
        $credit->rel_id = $invoice->id;
        $credit->description = $item->title;
        $credit->amount = -$total;
        $credit->created_at = date('c');
        $credit->updated_at = date('c');
        $this->di['db']->store($credit);

        $invoiceService = $this->di['mod_service']('Invoice');
        $invoiceService->addNote($invoice, sprintf('Charged clients balance with %s %s for %s', $total, $invoice->currency, $item->title));
    }

    public function getTotalWithTax(\Model_InvoiceItem $item)
    {
        return $this->getTotal($item) + $this->getTax($item) * $item->quantity;
    }

    public function getOrderId(\Model_InvoiceItem $item)
    {
        if($item->type == \Model_InvoiceItem::TYPE_ORDER) {
            return (int) $item->rel_id;
        }
        return 0;
    }

    protected function markAsExecuted(\Model_InvoiceItem $item)
    {
        $item->status         = \Model_InvoiceItem::STATUS_EXECUTED;
        $item->updated_at     = date('c');
        $this->di['db']->store($item);
    }

    public function generateFromOrder(\Model_Invoice $proforma, \Model_ClientOrder $order, $task, $price)
    {
        $corderService = $this->di['mod_service']('Order');

        $clientService = $this->di['mod_service']('client');
        $client = $this->di['db']->load('Client', $order->client_id);
        $taxed = $clientService->isClientTaxable($client);

        $pi = $this->di['db']->dispense('InvoiceItem');
        $pi->invoice_id    = $proforma->id;
        $pi->type           = \Model_InvoiceItem::TYPE_ORDER;
        $pi->rel_id         = $order->id;
        $pi->task           = $task;
        $pi->status         = \Model_InvoiceItem::STATUS_PENDING_PAYMENT;
        $pi->title          = $order->title;
        $pi->period         = $order->period;
        $pi->quantity       = $order->quantity;
        $pi->unit           = $order->unit;
        $pi->price          = $price;
        $pi->taxed          = $taxed;
        $pi->created_at     = date('c');
        $pi->updated_at     = date('c');
        $this->di['db']->store($pi);

        $corderService->setUnpaidInvoice($order, $proforma);
    }
}