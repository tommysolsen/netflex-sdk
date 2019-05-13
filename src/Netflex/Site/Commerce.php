<?php

namespace Netflex\Site;

use NF;

/**
 * @deprecated v1.1.0
 */
class Commerce
{
  /** @var string */
  public $orderSecret = null;

  public function __construct()
  {
    \trigger_deprecation(self::class);

    if (session_status() === PHP_SESSION_NONE) {
      session_start();
    }

    if (isset($_SESSION['netflex_cart'])) {
      $this->orderSecret = $_SESSION['netflex_cart'];
    }

    if (!$this->orderSecret && isset($_COOKIE['netflex_cart'])) {
      $this->orderSecret = $_COOKIE['netflex_cart'];
    }
  }

  /**
   * Reset order
   *
   * @return void
   */
  public function reset()
  {
    $_SESSION['netflex_cart'] = null;
    $_COOKIE['netflex_cart'] = null;
    unset($_SESSION['netflex_cart']);
    unset($_COOKIE['netflex_cart']);
    $this->orderSecret = null;
  }

  /**
   * Get order from secret
   *
   * @param string $secret
   * @return array|null
   */
  public function get_order_from_secret($secret = null)
  {

    if ($secret == null) {
      $secret = $this->orderSecret;
    }
    NF::debug($secret, 'Order secret');
    if ($secret) {
      $order = NF::$cache->fetch("order/$secret");
      if ($order == null) {
        $request = NF::$capi->get('commerce/orders/secret/' . $secret);
        $order = json_decode($request->getBody(), true);
        NF::$cache->save("order/$secret", $order, 3600);
      }
      return $order;
    }
  }

  /**
   * Get order by ID
   *
   * @param int $id
   * @return array
   */
  public function get_order($id)
  {
    $order = NF::$cache->fetch('order/' . $id);

    if (is_null($order)) {
      $request = NF::$capi->get('commerce/orders/' . $id);
      $order = json_decode($request->getBody(), true);
      NF::$cache->save('order/' . $id, $order, 3600);
    }

    return $order;
  }

  /**
   * Reset order cache
   *
   * @param array $order
   * @return void
   */
  public function reset_order_cache($order)
  {
    NF::$cache->delete('order/' . $order['id']);
    NF::$cache->delete('order/' . $order['secret']);
  }

  /**
   * Add item to cart
   *
   * @param array $cart_item
   * @param array $order = null
   * @return array
   */
  public function cart_add(array $cart_item, $order = null)
  {
    if (is_null($order)) {
      if (isset($_SESSION['netflex_siteuser_id'])) {
        $customer_id = $_SESSION['netflex_siteuser_id'];
        $request = NF::$capi->post('commerce/orders', ['json' => ['customer_id' => $customer_id]]);
      } else {
        $customer_id = 0;
        $request = NF::$capi->post('commerce/orders');
      }
      $createOrder = json_decode($request->getBody(), true);
      $order = $this->get_order($createOrder['order_id']);
      $_SESSION['netflex_cart'] = $createOrder['secret'];
    }

    $cart = $order['cart']['items'];
    if (count($cart)) {
      foreach ($cart as $item) {
        if ($cart_item['entries_comments'] == null && $cart_item['properties'] == null) {
          if (($cart_item['entry_id'] == $item['entry_id']) && ($cart_item['variant_id'] == $item['variant_id'])) {
            $found_id = $item['id'];
            $current_amount = $item['no_of_entries'];
            $create = 0;
          } else {
            $create = 1;
          }
        } else {
          $create = 1;
        }
      }
    } else {
      $create = 1;
    }

    if ($create) {
      $cart_item['ip'] = get_client_ip();
      $cart_item['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
      NF::$capi->post('commerce/orders/' . $order['id'] . '/cart', ['json' => $cart_item]);
    } else {
      $cart_item['changed_in_cart'] = 1;
      if (!isset($cart_item['reset_quantity'])) {
        $cart_item['no_of_entries'] = $current_amount + $cart_item['no_of_entries'];
      }
      NF::$capi->put('commerce/orders/' . $order['id'] . '/cart/' . $found_id, ['json' => $cart_item]);
    }

    $this->reset_order_cache($order);
    return $createOrder;
  }

  /**
   * Create order
   *
   * @param int $customer_id
   * @return array
   */
  public function create($customer_id = 0)
  {

    if ($customer_id) {
      $request = NF::$capi->post('commerce/orders', ['json' => ['customer_id' => $customer_id]]);
    } else {
      $request = NF::$capi->post('commerce/orders');
    }

    $createOrder = json_decode($request->getBody(), true);
    $order = $this->get_order($createOrder['order_id']);

    $_SESSION['netflex_cart'] = $createOrder['secret'];
    $this->orderSecret = $createOrder['secret'];
    $this->reset_order_cache($order);

    return $order;
  }

  /**
   * Update item in cart
   *
   * @param array $order
   * @param int $item_id
   * @param array $data
   * @return array
   */
  public function update_cart_item($order, $item_id, $data)
  {
    $request = NF::$capi->put('commerce/orders/' . $order['id'] . '/cart/' . $item_id, ['json' => $data]);
    $this->reset_order_cache($order);
    return json_decode($request->getBody(), true);
  }

  /**
   * Delete item from cart
   *
   * @param array $order
   * @param int $item_id
   * @return void
   */
  public function delete_cart_item($order, $item_id)
  {
    NF::$capi->delete('commerce/orders/' . $order['id'] . '/cart/' . $item_id);
    $this->reset_order_cache($order);
  }

  /**
   * Delete cart
   *
   * @param array $order
   * @return void
   */
  public function delete_cart($order)
  {
    NF::$capi->delete('commerce/orders/' . $order['id'] . '/cart');
    $this->reset_order_cache($order);
  }

  /**
   * Get orders for customer ID
   *
   * @param int $customer_id
   * @return array<array>
   */
  public function order_get_customer_orders($customer_id)
  {
    $request = NF::$capi->get('commerce/orders/customer/' . $customer_id);
    return json_decode($request->getBody(), true);
  }

  /**
   * Checkout order
   *
   * @param array $data
   * @param array $order
   * @return array
   */
  public function order_checkout(array $data, $order)
  {
    $request = NF::$capi->put('commerce/orders/' . $order['id'] . '/checkout', ['json' => $data]);
    $this->reset_order_cache($order);
    return json_decode($request->getBody(), true);
  }

  /**
   * Add payment to order
   *
   * @param array $data
   * @param array $order
   * @return array
   */
  public function order_add_payment(array $data, $order)
  {
    $request = NF::$capi->post('commerce/orders/' . $order['id'] . '/payment', ['json' => $data]);
    $this->reset_order_cache($order);
    return json_decode($request->getBody(), true);
  }

  /**
   * Log event on order
   *
   * @param array $data
   * @param array $order
   * @return array
   */
  public function order_log(array $data, $order)
  {
    $request = NF::$capi->post('commerce/orders/' . $order['id'] . '/log', ['json' => $data]);
    $this->reset_order_cache($order);
    return json_decode($request->getBody(), true);
  }

  /**
   * Update order
   *
   * @param array $data
   * @param array $order
   * @return array
   */
  public function order_update(array $data, $order)
  {
    $request = NF::$capi->put('commerce/orders/' . $order['id'], ['json' => $data]);
    $this->reset_order_cache($order);
    return json_decode($request->getBody(), true);
  }

  /**
   * Register order
   *
   * @param array $order
   * @return array
   */
  public function order_register($order)
  {
    $request = NF::$capi->put('commerce/orders/' . $order['id'] . '/register');
    $this->reset_order_cache($order);
    return json_decode($request->getBody(), true);
  }

  /**
   * Put order data
   *
   * @param array $data
   * @param array $order
   * @return array
   */
  public function order_data(array $data, $order)
  {
    $request = NF::$capi->put('commerce/orders/' . $order['id'] . '/data', ['json' => $data]);
    $this->reset_order_cache($order);
    return json_decode($request->getBody(), true);
  }

  /**
   * Send order document
   *
   * @param array $data
   * @param string $document
   * @param array $order
   * @return void
   */
  public function order_send_document(array $data, $document, $order)
  {
    $request = NF::$capi->post('commerce/orders/' . $order['id'] . '/document/' . $document, ['json' => $data]);
    $this->reset_order_cache($order);
    return json_decode($request->getBody(), true);
  }

  /**
   * Get order document
   *
   * @param string $document
   * @param array $order
   * @return array
   */
  public function order_get_document($document, $order)
  {
    $request = NF::$capi->get('commerce/orders/' . $order['id'] . '/document/' . $document);
    $this->reset_order_cache($order);
    return json_decode($request->getBody(), true);
  }
}
