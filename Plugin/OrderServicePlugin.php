<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\OrderStatusFix\Plugin;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Config;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\Service\OrderService;

class OrderServicePlugin
{
    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender
     */
    private $orderCommentSender;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var \Magento\Sales\Model\Order\Config|mixed
     */
    private $orderConfig;

    /**
     * OrderServicePlugin constructor.
     *
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender $orderCommentSender
     * @param \Magento\Sales\Api\OrderRepositoryInterface                $orderRepository
     * @param \Magento\Sales\Model\Order\Config|null                     $orderConfig
     */
    public function __construct(
        OrderCommentSender $orderCommentSender,
        OrderRepositoryInterface $orderRepository,
        Config $orderConfig = null
    ) {
        $this->orderCommentSender = $orderCommentSender;
        $this->orderRepository    = $orderRepository;
        $this->orderConfig        = $orderConfig ?: ObjectManager::getInstance()->get(Config::class);
    }

    /**
     * @param OrderService                $subject
     * @param callable                    $proceed
     * @param int                         $id
     * @param OrderStatusHistoryInterface $statusHistory
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function aroundAddComment(OrderService $subject, callable $proceed, $id, OrderStatusHistoryInterface $statusHistory): bool
    {
        $order = $this->orderRepository->get($id);
        $statuses = $this->orderConfig->getStateStatuses($order->getState());
        $orderStatus = $order->getStatus();
        $orderStatusHistory = $statusHistory->getStatus();
        if ($orderStatusHistory) {
            /**
             * change order status in the scope of different state is not allowed during add comment to the order
             */
            if (!array_key_exists($orderStatusHistory, $statuses)) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __(
                        'Unable to add comment: The status "%1" is not part of the order status history.',
                        $orderStatusHistory
                    )
                );
            }
            $orderStatus = $orderStatusHistory;
        }

        $statusHistory->setStatus($orderStatus);
        $order->setStatus($orderStatus);

        $order->addStatusHistory($statusHistory);
        $this->orderRepository->save($order);
        $notify = $statusHistory['is_customer_notified'] ?? false;
        $comment = $statusHistory->getComment() !== null ? trim(strip_tags($statusHistory->getComment())) : '';
        $this->orderCommentSender->send($order, $notify, $comment);

        return true;
    }
}