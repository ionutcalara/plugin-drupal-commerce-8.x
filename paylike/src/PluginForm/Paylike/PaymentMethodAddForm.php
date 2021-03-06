<?php

namespace Drupal\commerce_paylike\PluginForm\Paylike;

use Drupal\commerce\InlineFormManager;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PaymentMethodAddForm extends BasePaymentMethodAddForm {

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new PaymentMethodFormBase.
   *
   * @param \Drupal\commerce_store\CurrentStoreInterface $current_store
   *   The current store.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce\InlineFormManager $inline_form_manager
   *   The inline form manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   */
  public function __construct(CurrentStoreInterface $current_store, EntityTypeManagerInterface $entity_type_manager, InlineFormManager $inline_form_manager, LoggerInterface $logger, RouteMatchInterface $route_match) {
    parent::__construct($current_store, $entity_type_manager, $inline_form_manager, $logger);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_store.current_store'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_inline_form'),
      $container->get('logger.channel.commerce_payment'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_paylike\Plugin\Commerce\PaymentGateway\Paylike $plugin */
    $plugin = $this->plugin;

    /** @var \Drupal\commerce_order\Entity\Order $order */
    if ($order = $this->routeMatch->getParameter('commerce_order')) {
      $products = [];
      foreach ($order->getItems() as $orderItem) {
        $products[$orderItem->id()] = array(
          'id' => $orderItem->id(),
          'title' => $orderItem->label(),
          'price' => (string) $orderItem->getUnitPrice(),
          'quantity' => $orderItem->getQuantity(),
          'total' => (string) $orderItem->getTotalPrice(),
        );
      }

      $commerceInfo = system_get_info('module', 'commerce');
      $addressInfo = $this->getAddressInfo($order);

      // Paylike popup settings
      $element['#attached']['drupalSettings']['commercePaylike'] = [
        'publicKey' => $plugin->getPublicKey(),
        'config' => [
          'currency' => $order->getTotalPrice()->getCurrencyCode(),
          'amount' => $plugin->toMinorUnits($order->getTotalPrice()),
          'locale' => \Drupal::languageManager()->getCurrentLanguage()->getId(),
          'title' => $plugin->getPopupTitle(),
          'descriptor' => t("Order #@order_id", ["@order_id" => $order->id()]),
          'custom' => [
            'email' => $order->getEmail(),
            'orderId' => $order->id(),
            'products' => $products,
            'customer' => [
              'email' => $order->getEmail(),
              'IP' => $order->getIpAddress(),
              'name' => $addressInfo['name'],
              'phoneNo' => '', // No phone information
              'address' => $addressInfo['address'],
            ],
            'platform' => [
              'name' => 'Drupal',
              'version' => \DRUPAL::VERSION,
            ],
            'ecommerce' => [
              'name' => 'Drupal Commerce',
              'version' => $commerceInfo['version'],
            ],
          ],
        ],
      ];
      $element['#attached']['library'][] = 'commerce_paylike/form';
      $element['paylike_button'] = [
        '#type' => 'button',
        '#value' => $this->t('Enter credit card details'),
        '#attributes' => [
          'class' => ['paylike-button'],
        ],
        '#prefix' => '<p class="paylike-description">' . $plugin->getDescription() . '</p>',
      ];
      $element['paylike_transaction_id'] = [
        '#type' => 'hidden',
        '#attributes' => [
          'id' => 'paylike_transaction_id',
        ],
      ];
    }

    return $element;
  }

  /**
   * Validates the credit card form.
   *
   * @param array $element
   *   The credit card form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the complete form.
   */
  protected function validateCreditCardForm(array &$element, FormStateInterface $form_state) {
    $values = $form_state->getValue($element['#parents']);
    if (!$values['paylike_transaction_id']) {
      $form_state->setError($element['paylike_transaction_id'], t('Transaction failed.'));
      return;
    }
  }

  /**
   * Handles the submission of the credit card form.
   *
   * @param array $element
   *   The credit card form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the complete form.
   */
  protected function submitCreditCardForm(array $element, FormStateInterface $form_state) {
    $values = $form_state->getValue($element['#parents']);
    $this->entity->paylike_transaction_id = $values['paylike_transaction_id'];
  }

  /**
   * Returns address and name data in array format.
   * @param \Drupal\commerce_order\Entity\Order $order
   * @return array
   */
  protected function getAddressInfo(Order $order) {
    $entity_manager = \Drupal::entityTypeManager();
    $billingProfile = $order->getBillingProfile();
    if (!$billingProfile) {
      $customer = $order->getCustomer();
      $billingProfile = $entity_manager->getStorage('profile')->loadDefaultByUser($customer, 'customer');
    }

    $data = [
      'address' => '',
      'name' => '',
    ];
    if ($billingProfile) {
      $addressInfo = $billingProfile->get('address')->first()->toArray();
      $data['address'] = implode(', ', array_filter([
        $addressInfo['postal_code'],
        $addressInfo['country_code'],
        $addressInfo['administrative_area'],
        $addressInfo['locality'],
        $addressInfo['address_line1'],
        $addressInfo['address_line2'],
      ]));
      $data['name'] = implode(' ', array_filter([
        $addressInfo['family_name'],
        $addressInfo['given_name'],
      ]));
    }

    return $data;
  }
}
