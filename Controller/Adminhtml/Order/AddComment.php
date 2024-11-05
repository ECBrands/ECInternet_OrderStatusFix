<?php

declare(strict_types=1);

namespace ECInternet\OrderStatusFix\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Registry;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\View\Result\LayoutFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Psr\Log\LoggerInterface;
use ECInternet\OrderStatusFix\Model\Config;

/**
 * Class AddComment
 *
 * Controller responsible for addition of the order comment to the order
 */
class AddComment extends \Magento\Sales\Controller\Adminhtml\Order implements HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Magento_Sales::comment';

    /**
     * ACL resource needed to send comment email notification
     */
    public const ADMIN_SALES_EMAIL_RESOURCE = 'Magento_Sales::emails';

    /**
     * @var \ECInternet\OrderStatusFix\Model\Config
     */
    private $config;

    /**
     * AddComment constructor.
     *
     * @param \Magento\Backend\App\Action\Context              $context
     * @param \Magento\Framework\Registry                      $coreRegistry
     * @param \Magento\Framework\App\Response\Http\FileFactory $fileFactory
     * @param \Magento\Framework\Translate\InlineInterface     $translateInline
     * @param \Magento\Framework\View\Result\PageFactory       $resultPageFactory
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Magento\Framework\View\Result\LayoutFactory     $resultLayoutFactory
     * @param \Magento\Framework\Controller\Result\RawFactory  $resultRawFactory
     * @param \Magento\Sales\Api\OrderManagementInterface      $orderManagement
     * @param \Magento\Sales\Api\OrderRepositoryInterface      $orderRepository
     * @param \Psr\Log\LoggerInterface                         $logger
     * @param \ECInternet\OrderStatusFix\Model\Config          $config
     */
    public function __construct(
        Action\Context $context,
        Registry $coreRegistry,
        FileFactory $fileFactory,
        InlineInterface $translateInline,
        PageFactory $resultPageFactory,
        JsonFactory $resultJsonFactory,
        LayoutFactory $resultLayoutFactory,
        RawFactory $resultRawFactory,
        OrderManagementInterface $orderManagement,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger,
        Config $config
    ) {
        parent::__construct(
            $context,
            $coreRegistry,
            $fileFactory,
            $translateInline,
            $resultPageFactory,
            $resultJsonFactory,
            $resultLayoutFactory,
            $resultRawFactory,
            $orderManagement,
            $orderRepository,
            $logger
        );

        $this->config = $config;
    }

    /**
     * Add order comment action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $order = $this->_initOrder();
        if ($order) {
            try {
                $data = $this->getRequest()->getPost('history');
                if (empty($data['comment']) && $data['status'] == $order->getDataByKey('status')) {
                    $error = 'Please provide a comment text or ' .
                        'update the order status to be able to submit a comment for this order.';
                    throw new \Magento\Framework\Exception\LocalizedException(__($error));
                }

                $orderStatus = $this->getOrderStatus($order, $data['status']);
                $order->setStatus($orderStatus);
                $notify = $data['is_customer_notified'] ?? false;
                $visible = $data['is_visible_on_front'] ?? false;

                if ($notify && !$this->_authorization->isAllowed(self::ADMIN_SALES_EMAIL_RESOURCE)) {
                    $notify = false;
                }

                $comment = trim(strip_tags($data['comment']));
                $history = $order->addStatusHistoryComment($comment, $orderStatus);
                $history->setIsVisibleOnFront($visible);
                $history->setIsCustomerNotified($notify);
                $history->save();

                $order->save();
                /** @var OrderCommentSender $orderCommentSender */
                $orderCommentSender = $this->_objectManager
                    ->create(\Magento\Sales\Model\Order\Email\Sender\OrderCommentSender::class);

                $orderCommentSender->send($order, $notify, $comment);

                return $this->resultPageFactory->create();
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                $response = ['error' => true, 'message' => $e->getMessage()];
            } catch (\Exception $e) {
                $response = ['error' => true, 'message' => __('We cannot add order history.')];
            }
            if (is_array($response)) {
                $resultJson = $this->resultJsonFactory->create();
                $resultJson->setData($response);
                return $resultJson;
            }
        }
        return $this->resultRedirectFactory->create()->setPath('sales/*/');
    }

    /**
     * Get order status to set
     *
     * @param OrderInterface $order
     * @param string $historyStatus
     * @return string
     */
    private function getOrderStatus(OrderInterface $order, string $historyStatus): string
    {
        $config = $order->getConfig();
        if ($config === null) {
            return $historyStatus;
        }

        // Only perform check if the configuration is not set to allow any order status change
        if (!$this->config->isAllowAnyOrderStatusChange()) {
            $statuses = $config->getStateStatuses($order->getState());

            if (!isset($statuses[$historyStatus])) {
                return $order->getDataByKey('status');
            }
        }

        return $historyStatus;
    }
}
