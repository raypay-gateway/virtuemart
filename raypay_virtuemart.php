<?php

/**
 * RayPay payment plugin
 *
 * @developer hanieh729
 * @publisher RayPay
 * @package VirtueMart
 * @subpackage payment
 * @copyright (C) 2021 RayPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * https://raypay.ir
 */

defined('_JEXEC') or die('Restricted access');


if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . '/vmpsplugin.php');
}

use Joomla\CMS\Http\Http;
use Joomla\CMS\Http\HttpFactory;

class plgVmPaymentRayPay_virtuemart extends vmPSPlugin
{
	private $http;

	/**
	 * plgVmPaymentRayPay_virtuemart constructor.
	 *
	 * @param              $subject
	 * @param              $config
	 * @param   Http|null  $http
	 */
	function __construct(&$subject, $config, Http $http = null)
	{
		$this->http = $http ?: HttpFactory::getHttp();
		parent::__construct($subject, $config);
		$this->_loggable   = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey  = 'id';
		$this->_tableId    = 'id';
		$varsToPush        = array('user_id' => array('', 'varchar'), 'sandbox' => array(0, 'int'), 'marketing_id' => array('', 'varchar'));
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	/**
	 * @return mixed
	 */
	public function getVmPluginCreateTableSQL()
	{
		return $this->createTableSQL('Payment RayPay Table');
	}

	/**
	 * @return string[]
	 */
	function getTableSQLFields()
	{
		$SQLfields = array(
			'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'int(1) UNSIGNED',
			'order_number'                => 'char(64)',
			'order_pass'                  => 'varchar(50)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'crypt_virtuemart_pid'        => 'varchar(255)',
			'salt'                        => 'varchar(255)',
			'payment_name'                => 'varchar(5000)',
			'amount'                      => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'            => 'char(3)',
			'email_currency'              => 'char(3)',
			'mobile'                      => 'varchar(12)',
			'tracking_code'               => 'varchar(100)',
			'raypay_invoice_id'           => 'varchar(100)'
		);

		return $SQLfields;
	}


	/**
	 * @param $cart
	 * @param $order
	 *
	 * @return |null
	 */
	function plgVmConfirmedOrder($cart, $order)
	{
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id))
		{
			return null;
		}

		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)))
		{
			return null;
		}

		$session             = JFactory::getSession();
		$salt                = JUserHelper::genRandomPassword(32);
		$crypt_virtuemartPID = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id, $salt);
		if ($session->isActive('raypay'))
		{
			$session->clear('raypay');
		}
		$session->set('raypay', $crypt_virtuemartPID);
		$payment_currency       = $this->getPaymentCurrency($method, $order['details']['BT']->payment_currency_id);
		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $payment_currency);
		$email_currency         = $this->getEmailCurrency($method);
		$app                    = JFactory::getApplication();
		$user_id                = $method->user_id;
		$marketing_id          = $method->marketing_id;
        $sandbox = !($method->sandbox == 0);
		$amount                 = $totalInPaymentCurrency['value'];
		$invoice_id             = round(microtime(true) * 1000);
		$desc                   = '???????? ?????????? ???? ??????????????   ' . $cart->vendor->vendor_store_name;
		$callback               = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&gw=RayPay';

		if (empty($amount))
		{
			$msg  = '???????? ?????? ???????????? ?????? ???????????????? ?????? ??????.';
			$link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
			$app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
		}

		// Customer information
		$name  = $order['details']['BT']->first_name . ' ' . $order['details']['BT']->last_name;
		$phone = $order['details']['BT']->phone_2;
		$mail  = $order['details']['BT']->email;

		$url  = 'https://api.raypay.ir/raypay/api/v1/Payment/pay';

		$data = array(
			'amount'       => strval($amount),
			'invoiceID'    => strval($invoice_id),
			'userID'       => $user_id,
			'redirectUrl'  => $callback,
			'factorNumber' => strval($order['details']['BT']->order_number),
			'marketingID' => $marketing_id,
			'email'        => $mail,
			'mobile'       => $phone,
			'fullName'     => $name,
			'comment'      => $desc,
            'enableSandBox'      => $sandbox
		);


		// $options     = $this->options();
		// $result      = $this->http->post($url, json_encode($data, true), $options);
		// $result      = json_decode($result->body);
		// $http_status = $result->StatusCode;
		$options = array('Content-Type: application/json');
		$ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER,$options );
        $result = curl_exec($ch);
        $result = json_decode($result );
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

		//insert raypay table jnfo
		$dbValues['payment_name']                = $this->renderPluginName($method) . '<br />';
		$dbValues['order_number']                = $order['details']['BT']->order_number;
		$dbValues['order_pass']                  = $order['details']['BT']->order_pass;
		$dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['crypt_virtuemart_pid']        = $crypt_virtuemartPID;
		$dbValues['salt']                        = $salt;
		$dbValues['payment_currency']            = $order['details']['BT']->order_currency;
		$dbValues['email_currency']              = $email_currency;
		$dbValues['amount']                      = $totalInPaymentCurrency['value'];
		$dbValues['mobile']                      = $order['details']['BT']->phone_2;
		$dbValues['raypay_invoice_id']           = $invoice_id;
		$this->storePSPluginInternalData($dbValues);

		if ($http_status != 200 || empty($result) || empty($result->Data))
		{
			$msg = '?????? ?????????? ?????????? ????????????. ?????????? ??????:' . $http_status . "<br>" . ' ?????????? ?????? ' . $result->Message;
			$this->updateStatus('P', 0, $msg, $order['details']['BT']->virtuemart_order_id);
			$this->updateOrderInfo($order['details']['BT']->virtuemart_order_id, $msg);
			$link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
			$app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
		}

		$token = $result->Data;
        $link='https://my.raypay.ir/ipg?token=' . $token;
        Header('Location: ' . $link);
        return False;
    }

	/**
	 * @param $html
	 *
	 * @return |null
	 */
	public function plgVmOnPaymentResponseReceived(&$html)
	{
		if (!class_exists('VirtueMartModelOrders'))
		{
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
        if (!class_exists('VirtueMartCart')) {
            require JPATH_VM_SITE.DS.'helpers'.DS.'cart.php';
        }

		$app        = JFactory::getApplication();
		$jinput     = $app->input;
		$gateway    = $jinput->get->get('gw', '', 'STRING');

		if ($gateway == 'RayPay')
		{
			$session = JFactory::getSession();
			if ($session->isActive('raypay') && $session->get('raypay') != null)
			{
				$cryptID = $session->get('raypay');
			}
			else
			{
				$msg  = '?????????? ???????? ??????';
				$link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
				$app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
			}
			$orderInfo = $this->getOrderInfo($cryptID);

			if ($orderInfo != null)
			{
				if (!($currentMethod = $this->getVmPluginMethod($orderInfo->virtuemart_paymentmethod_id)))
				{
					return null;
				}
			}
			else
			{
				return null;
			}

			$salt       = $orderInfo->salt;
			$id         = $orderInfo->virtuemart_order_id;
			$uId        = $cryptID . ':' . $salt;
			$order_id   = $orderInfo->order_number;
			$payment_id = $orderInfo->virtuemart_paymentmethod_id;
			$pass_id    = $orderInfo->order_pass;
			$price      = round($orderInfo->amount, 5);
			$method     = $this->getVmPluginMethod($payment_id);

			if (JUserHelper::verifyPassword($id, $uId))
			{
				if (!empty($orderInfo))
				{
					$url         = 'https://api.raypay.ir/raypay/api/v1/Payment/verify';
					$options = array('Content-Type: application/json');
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $url);
					curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($_POST));
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
					curl_setopt($ch, CURLOPT_HTTPHEADER,$options );
					$result = curl_exec($ch);
					$result = json_decode($result );
					$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
					curl_close($ch);
					// $options     = $this->options();
					// $result      = $this->http->post($url, json_encode($data, true), $options);
					// $result      = json_decode($result->body);
					// $http_status = $result->StatusCode;
					if ($http_status != 200)
					{
						$msg  = sprintf('?????? ?????????? ?????????? ?????????? ????????????. ?????????? ??????: %s - ???????? ??????: %s', $http_status, $result->Message);
						$link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
						$app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
					}

					$state           = $result->Data->Status;
					$verify_order_id = $result->Data->FactorNumber;
                    $verify_invoice_id = $result->Data->InvoiceID;
					$verify_amount   = $result->Data->Amount;

					if ($state === 1)
					{
						$verify_status = ' ???????????? ???????? ';
					}
					else
					{
						$verify_status = '  ???????????? ???????????? ';
					}


					if (empty($verify_order_id) || empty($verify_amount) || $state !== 1)
					{
						$msg  = '???????????? ???????????? ???????? ??????.';
						$link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
						$app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
					}
					else
					{
						$msg  = '???????????? ?????? ???? ???????????? ?????????? ????.';
                        $app->enqueueMessage($msg);
						$html = $this->renderByLayout('raypay_virtuemart', array(
							'order_number' => $order_id,
							'order_pass'   => $pass_id,
							'status'       => $msg
						));

						$msgForSaveDataTDataBase = " ?????????? ?????????? ?????????? ?????? ???? : " . $verify_invoice_id;
						$this->updateStatus('C', 0, $msgForSaveDataTDataBase, $id);
						$this->updateOrderInfo($id, sprintf('?????????? ???????????? ????????????: %s', $verify_status));
						vRequest::setVar('html', $html);
						$cart = VirtueMartCart::getCart();
						$cart->emptyCart();
						$session->clear('raypay');
					}
				}
				else
				{
					$msg  = '?????????? ???????? ??????';
					$link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
					$app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
				}
			}
			else{
				return null;
			}
		}

		return false;
	}

	/**
	 * @param $id
	 *
	 * @return mixed
	 */
	protected
	function getOrderInfo($id)
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*')
			->from($db->qn('#__virtuemart_payment_plg_raypay_virtuemart'));
		$query->where($db->qn('crypt_virtuemart_pid') . ' = ' . $db->q($id));
		$db->setQuery((string) $query);
		$result = $db->loadObject();

		return $result;
	}

	/**
	 * @param $id
	 * @param $trackingCode
	 */
	protected
	function updateOrderInfo($id, $trackingCode)
	{
		$db         = JFactory::getDbo();
		$query      = $db->getQuery(true);
		$fields     = array($db->qn('tracking_code') . ' = ' . $db->q($trackingCode));
		$conditions = array($db->qn('virtuemart_order_id') . ' = ' . $db->q($id));
		$query->update($db->qn('#__virtuemart_payment_plg_raypay_virtuemart'));
		$query->set($fields);
		$query->where($conditions);
		$db->setQuery($query);
		$db->execute();
	}

	/**
	 * @param $cart
	 * @param $method
	 * @param $cart_prices
	 *
	 * @return bool
	 */
	protected
	function checkConditions($cart, $method, $cart_prices)
	{
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		if ($this->_toConvert)
		{
			$this->convertToVendorCurrency($method);
		}

		$countries = array();
		if (!empty($method->countries))
		{
			if (!is_array($method->countries))
			{
				$countries[0] = $method->countries;
			}
			else
			{
				$countries = $method->countries;
			}
		}

		if (!is_array($address))
		{
			$address                          = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id']))
		{
			$address['virtuemart_country_id'] = 0;
		}
		if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries))
		{
			return true;
		}

		return false;
	}

	/**
	 * @param   VirtueMartCart  $cart
	 * @param   int             $selected
	 * @param                   $htmlIn
	 *
	 * @return bool
	 */
	public
	function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
	{
		if ($this->getPluginMethods($cart->vendorId) === 0)
		{
			if (empty($this->_name))
			{
				$app = JFactory::getApplication();
				$app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));

				return false;
			}
			else
			{
				return false;
			}
		}
		$method_name = $this->_psType . '_name';

		$htmla = array();
		foreach ($this->methods as $this->_currentMethod)
		{
			if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices))
			{

				$html       = '';
				$cartPrices = $cart->cartPrices;
				if (isset($this->_currentMethod->cost_method))
				{
					$cost_method = $this->_currentMethod->cost_method;
				}
				else
				{
					$cost_method = true;
				}
				$methodSalesPrice = $this->setCartPrices($cart, $cartPrices, $this->_currentMethod, $cost_method);

				$this->_currentMethod->payment_currency = $this->getPaymentCurrency($this->_currentMethod);
				$this->_currentMethod->$method_name     = $this->renderPluginName($this->_currentMethod);
				$html                                   .= $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
				$htmla[]                                = $html;
			}
		}
		$htmlIn[] = $htmla;

		return true;
	}


	/**
	 * @param   VirtueMartCart  $cart
	 * @param                   $msg
	 *
	 * @return |null
	 */
	public
	function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
	{
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id))
		{
			return null;
		}

		return $this->OnSelectCheck($cart);
	}

	/**
	 * @param   VirtueMartCart  $cart
	 * @param   array           $cart_prices
	 * @param                   $paymentCounter
	 *
	 * @return mixed
	 */
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
	{
		return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
	}

	/**
	 * @param   VirtueMartCart  $cart
	 * @param   array           $cart_prices
	 * @param                   $cart_prices_name
	 *
	 * @return mixed
	 */
	public
	function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
	{
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * @param   VirtueMartCart  $cart
	 *
	 * @return bool|null
	 */
	public
	function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart)
	{
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id))
		{
			return null;
		}

		return true;
	}

	/**
	 * @param $jplugin_id
	 *
	 * @return mixed
	 */
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
	{
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	/**
	 * @param $order_number
	 * @param $method_id
	 *
	 * @return mixed
	 */
	function plgVmonShowOrderPrintPayment($order_number, $method_id)
	{
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	/**
	 * @param $data
	 *
	 * @return mixed
	 */
	function plgVmDeclarePluginParamsPaymentVM3(&$data)
	{
		return $this->declarePluginParams('payment', $data);
	}

	/**
	 * @param $name
	 * @param $id
	 * @param $table
	 *
	 * @return mixed
	 */
	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
	{
		return $this->setOnTablePluginParams($name, $id, $table);
	}

	/**
	 * @param         $method
	 * @param   bool  $selectedUserCurrency
	 *
	 * @return bool|false|string[]
	 */
	static function getPaymentCurrency(&$method, $selectedUserCurrency = false)
	{
		if (empty($method->payment_currency))
		{
			$vendor_model             = VmModel::getModel('vendor');
			$vendor                   = $vendor_model->getVendor($method->virtuemart_vendor_id);
			$method->payment_currency = $vendor->vendor_currency;

			return $method->payment_currency;
		}
		else
		{

			$vendor_model      = VmModel::getModel('vendor');
			$vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies($method->virtuemart_vendor_id);

			if (!$selectedUserCurrency)
			{
				if ($method->payment_currency == -1)
				{
					$mainframe            = JFactory::getApplication();
					$selectedUserCurrency = $mainframe->getUserStateFromRequest("virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt('virtuemart_currency_id', $vendor_currencies['vendor_currency']));
				}
				else
				{
					$selectedUserCurrency = $method->payment_currency;
				}
			}

			$vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
			if (in_array($selectedUserCurrency, $vendor_currencies['all_currencies']))
			{
				$method->payment_currency = $selectedUserCurrency;
			}
			else
			{
				$method->payment_currency = $vendor_currencies['vendor_currency'];
			}

			return $method->payment_currency;
		}

	}

	/**
	 * @param           $status
	 * @param           $notified
	 * @param   string  $comments
	 * @param           $id
	 */
	protected
	function updateStatus($status, $notified, $comments = '', $id)
	{
		$modelOrder                 = VmModel::getModel('orders');
		$order['order_status']      = $status;
		$order['customer_notified'] = $notified;
		$order['comments']          = $comments;
		$modelOrder->updateStatusForOneOrder($id, $order, true);
	}
}


