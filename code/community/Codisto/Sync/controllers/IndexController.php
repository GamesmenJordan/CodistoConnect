<?php
/**
 * Codisto eBay Sync Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category    Codisto
 * @package     Codisto_Sync
 * @copyright   Copyright (c) 2014 On Technology Pty. Ltd. (http://codisto.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Codisto_Sync_IndexController extends Mage_Core_Controller_Front_Action
{
	public function calcAction()
	{
		$request = $this->getRequest();
		$response = $this->getResponse();

		$model = Mage::getModel('catalog/product');
		
		$cart = Mage::getSingleton('checkout/cart');

		$postalcode = $request->getPost('POSTALCODE');
		$division = $request->getPost('DIVISION');
		$countrycode = $request->getPost('COUNTRYCODE');
		
		if($countrycode == 'AU')
		{
			$pc = $postalcode[0];

			if ($pc == 2 || $pc == 1) {
				$regiontext = "NSW";
			} else if ($pc == 3 || $pc == 8) {
				$regiontext = "VIC";
			} else if ($pc == 4) {
				$regiontext = "QLD";
			} else if ($pc == 5) {
				$regiontext = "SA";
			} else if ($pc == 6) {
				$regiontext = "WA";
			} else if ($pc == 7) {
				$regiontext = "TAS";
			}

			$pc3 = $postalcode[0] + $postalcode[1];
			if ($pc3 == "08" || $pc3 == "09") {
				$regiontext = "NT";
			}

			if ($postalcode == "0872") {
				$regiontext = "SA";
			} else if ($postalcode == "2611" || $postalcode == "3500" || $postalcode == "3585" || $postalcode == "3586" || $postalcode == "3644" || $postalcode == "3707") {
				$regiontext = "NSW";
			} else if ($postalcode == "2620") {
				$regiontext = "ACT";
			}

			if ($postalcode >= 2600 && $postalcode <= 2618) {
				$regiontext = "ACT";
			}

			$region = Mage::getModel('directory/region')->loadByCode($regiontext, $countrycode);
			if($region)
				$regionid = $region->getId();
		}
		else
		{
			$region = Mage::getModel('directory/region')->loadByName($division, $countrycode);
			if($region)
				$regionid = $region->getId();
		}
		
		for($inputidx = 0; ; $inputidx++)
		{
			$productcode = $request->getPost('PRODUCTCODE('.$inputidx.')');
			$id = $model->getIdBySku($productcode);
			
			$productqty = $request->getPost('PRODUCTQUANTITY('.$inputidx.')');
			if(!$productqty && $productqty !=0)
				$productqty = 1;
			
			if(!$productcode)
				break;
		
		
			if($id)
			{
				$product = $model->load($id);

				$cart->addProduct($product, $productqty);
			}
		}

		$address = $cart->getQuote()->getShippingAddress();
		
		$address->setCountryId($countrycode)->setPostcode($postalcode);

		if($regionid)
			$address->setRegionId($regionid);
		
		$cart->save();
	
		$rates = $cart->getQuote()->getShippingAddress()->getShippingRatesCollection();
		
		$output = '';
		$outputidx = 0;
		
		foreach ($rates as $rate) {
			$output .= 'FREIGHTNAME('.$outputidx.')=Freight&FREIGHTCHARGEINCTAX('.$outputidx.')='.$rate->getPrice() . '&';
			
			$outputidx++;
		}
		
		$response->setBody($output);
	}
	
	public function indexAction()
	{
		$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : "";
		
		if($method == 'POST')
		{
			if($content_type == "text/xml")
			{

				$xml = simplexml_load_string(file_get_contents("php://input"));
		
				$ordercontent = $xml->entry->content->children('http://api.ezimerchant.com/schemas/2009/');
			
				$orders = Mage::getModel('sales/order')->getCollection()->addAttributeToFilter('codisto_orderid', $ordercontent->orderid);

				$ordermatch = false;
				foreach ($orders as $order) {
					//syslog(1, print_r('matching order: ' . $order->getCodistoOrderid(), true));
					$ordermatch = true;
				}

				if($ordermatch) {

					$this->ProcessOrderSync($order->getCodistoOrderid(), $xml);
				
				} else {
				
					if(!$ordercontent->reason)
						$ordercontent->reason = "OrderCreated";

					if($ordercontent &&
						$ordercontent->reason == "OrderCreated")
					{
						$this->ProcessOrderCreate($xml, null);
					}
					
					else if($ordercontent &&
						$ordercontent->reason == "OrderSync")
					{
						$this->ProcessOrderSync();
					}
					
					else if($ordercontent &&
						$ordercontent->reason == "ProductSync")
					{
						$this->ProductSync();
					}
				
				}
			}
		}
		else
		{
		
			include_once Mage::getBaseDir() . '/errors/404.php';
			 
		}
	}

	private function ProcessOrderCreate($xml, $codisto_orderid)
	{
		$website = Mage::app()->getWebsite();
		$websiteId = $website->getId();

		$store = Mage::app()->getStore();
		$storeId = $store->getId();
	
		$ordercontent = $xml->entry->content->children('http://api.ezimerchant.com/schemas/2009/');
		
		$currencyCode = $ordercontent->transactcurrency[0];
		$ebaysalesrecordnumber = $ordercontent->ebaysalesrecordnumber[0];
		$freightcarrier = 'Post';
		$freightservice = 'Freight';

		$billing_address = $ordercontent->orderaddresses->orderaddress[0];
		$billing_name = explode(" ", $billing_address->name, 2);
		$shipping_address = $ordercontent->orderaddresses->orderaddress[1];
		$shipping_name = explode(" ", $shipping_address->name, 2);
		
		$customer = Mage::getModel('customer/customer');
		$customer->setWebsiteId(Mage::app()->getWebsite()->getId());
		$customer->loadByEmail($billing_address->email);

		$regionCollection = $this->getRegionCollection($billing_address->countrycode);

		$regionsel_id = 0;
		foreach($regionCollection as $region) 
		{
				// TODO : deal with name
				if($region['code'] == $billing_address->division)
				{

					$regionsel_id = $region['region_id'];
				}		
		}
		
		$addressData_billing = array(
									'firstname' => $billing_name[0],
									'lastname' => $billing_name[1],
									'street' => $billing_address->address1.','.$billing_address->address2,
									'city' => $billing_address->place,
									'postcode' => $billing_address->postalcode,
									'telephone' => $billing_address->phone,
									'country_id' => $billing_address->countrycode,
									'region_id' => $regionsel_id, // id from directory_country_region table// id from directory_country_region table
							);

		$regionsel_id_ship = 0;
		foreach($regionCollection as $region) 
		{
				// TODO : deal with name
				if($region['code'] == $shipping_address->division)
				{

					$regionsel_id_ship = $region['region_id'];
				}		
		}
				
		$addressData_shipping = array(
				'firstname' => $shipping_name[0],
				'lastname' => $shipping_name[1],
				'street' => $shipping_address->address1.','.$shipping_address->address2,
				'city' => $shipping_address->place,
				'postcode' => $shipping_address->postalcode,
				'telephone' => $shipping_address->phone,
				'country_id' => $shipping_address->countrycode,
				'region_id' => $regionsel_id_ship, // id from directory_country_region table// id from directory_country_region table
		);

		
		if(!$customer->getId())
		{
			$ebayGroup = Mage::getModel('customer/group');
			$ebayGroup->load('eBay', 'customer_group_code');
			if(!$ebayGroup->getId())
			{
				$defaultGroup = Mage::getModel('customer/group')->load(1);
			
				$ebayGroup->setCode('eBay');
				$ebayGroup->setTaxClassId($defaultGroup->getTaxClassId());
				$ebayGroup->save();
			}

			$customer->setWebsiteId($websiteId);
			$customer->setStoreId($storeId);
			$customer->setEmail($billing_address->email);
			$customer->setFirstname($billing_name[0]);
			$customer->setLastname($billing_name[1]);
			$customer->setPassword('');
			$customer->setData('group_id', $ebayGroup->getId());
			$customer->save();
			$customer->setConfirmation(null);
			$customer->setStatus(1);
			$customer->save();

			$customerId = $customer->getId();

			$customerAddress = Mage::getModel('customer/address');
			$customerAddress->setData($addressData_billing)
									->setCustomerId($customer->getId())
									->setIsDefaultBilling(1)
									->setSaveInAddressBook(1);
			$customerAddress->save();


			$customerAddress->setData($addressData_shipping)
									->setCustomerId($customer->getId())
									->setIsDefaultShipping(1)
									->setSaveInAddressBook(1);
			$customerAddress->save();
		}
		
		$quote = Mage::getModel('sales/quote');
		$quote->assignCustomer($customer);
				
		$quote->getPayment()->setMethod('ebaypayment');
		
		$billingAddress  = $quote->getBillingAddress()->addData($addressData_billing);
		$shippingAddress = $quote->getShippingAddress()->addData($addressData_shipping);
		
		/*$totalinc = 0;
		$totalex = 0;*/
		
		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] != 'FREIGHT')
			{
				$product = Mage::getModel('catalog/product');
				$product->setSku($orderline->productcode[0]);
				$product->setName($orderline->productname[0]);
				
				$item = Mage::getModel('sales/quote_item');
				$item->setProduct($product);
				$item->setSku($orderline->productcode[0]);
				$item->setName($orderline->productname[0]);
				$item->setQty($orderline->quantity[0]);
				$item->setPrice($orderline->priceinctax[0]);
				$item->setOriginalPrice($orderline->listpriceinctax[0]);
				$item->setCustomPrice($orderline->priceinctax[0]);
				$item->setOriginalCustomPrice($orderline->listpriceinctax[0]);
			
				$quote->addItem($item);
				
				/*
				$totalinc += floatval($orderline->linetotalinctax[0]);
				$totalex += floatval($orderline->linetotal[0]);*/
			}
		}
		
		$freighttotal = 0;
		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] == 'FREIGHT')
			{
				$freighttotal += floatval($orderline->linetotalinctax[0]);
				/*$totalinc += floatval($orderline->linetotalinctax[0]);
				$totalex += floatval($orderline->linetotal[0]);*/
				$freightservice = $orderline->productname[0];
			}
		}
		
		$rate = Mage::getModel('sales/quote_address_rate');
		$rate->setCode('flatrate');
		$rate->setCarrier($freightcarrier);
		$rate->setCarrierTitle($freightcarrier);
		$rate->setMethod($freightservice);
		$rate->setMethodTitle($freightservice);
		$rate->setPrice($freighttotal);
		
		$shippingAddress = $quote->getShippingAddress();
		$shippingAddress->addShippingRate($rate);
		$shippingAddress->setShippingMethod('flatrate');
		$shippingAddress->setShippingDescription($freightservice);
		$shippingAddress->setShippingAmountForDiscount(0);

		$quote->collectTotals();
		
		/*$quote->setSubtotal($totalinc);
		$quote->setBaseSubtotal($totalinc);
		$quote->setGrandTotal($totalinc);
		$quote->setBaseGrandTotal($totalinc);*/
		
		$quote->save();
		
		$convertquote = Mage::getSingleton('sales/convert_quote');
		$order = $convertquote->toOrder($quote);
		$convertquote->addressToOrder($quote->getShippingAddress(), $order);
		$order->setGlobal_currency_code($currencyCode);
		$order->setBase_currency_code($currencyCode);
		$order->setStore_currency_code($currencyCode);
		$order->setOrder_currency_code($currencyCode);
		$order->setBillingAddress($convertquote->addressToOrderAddress($quote->getBillingAddress()));
		$order->setShippingAddress($convertquote->addressToOrderAddress($quote->getShippingAddress()));
		$order->setPayment($convertquote->paymentToOrderPayment($quote->getPayment()));
		$order->setCanShipPartiallyItem(false);

		$order->setCodistoOrderid($ordercontent->orderid);
	
		$lineidx = 0;
		foreach ($quote->getAllItems() as $item) {
		
			while(true)
			{
				$orderline = $ordercontent->orderlines->orderline[$lineidx];
				if($orderline->productcode[0] == 'FREIGHT')
				{
					$lineidx++;
					continue;
				}
				
				break;
			}

			$orderItem = $convertquote->itemToOrderItem($item);
			if ($item->getParentItem()) {
				$orderItem->setParentItem($order->getItemByQuoteItemId($item->getParentItem()->getId()));
			}
			
			$taxamount = $store->roundPrice(floatval($orderline->linetotalinctax[0]) - floatval($orderline->linetotal[0]));
			
			$orderItem->setBaseTaxAmount($taxamount);
			$orderItem->setTaxAmount($taxamount);
			$orderItem->setTaxPercent(round(floatval($orderline->priceinctax[0]) / floatval($orderline->price[0]) - 1.0, 2) * 100);
			$orderItem->setRowTotal(floatval($orderline->linetotal[0]));
			
			$order->addItem($orderItem);
			
			$lineidx++;
		}
		
		if($ebaysalesrecordnumber)
			$order->addStatusToHistory($order->getStatus(), "Order $ebaysalesrecordnumber received from eBay");
			
		/*$taxamount = $store->roundPrice(floatval($totalinc) - floatval($totalex));
		$taxpercent = round(floatval($totalinc) / floatval($totalex) - 1.0, 2) * 100;
		$order->setBaseTaxAmount($taxamount);
		$order->setTaxAmount($taxamount);
		$order->setTaxPercent($taxpercent);
		$order->setSubtotal($totalinc);
		$order->setBaseSubtotal($totalinc);
		$order->setGrandTotal($totalinc);
		$order->setBaseGrandTotal($totalinc);*/
		
		$order->place();
		$order->save();

		$quote->setIsActive(false)->save();

		$neworder = Mage::getModel('sales/order')->load($order->entity_id);
		
		$response = $this->getResponse();
		$response->setBody('OK');
	}
	
	private function ProcessOrderSync($codistoorderid, $xml)
	{
		$order = Mage::getModel('sales/order')->getCollection()->addAttributeToFilter('codisto_orderid', $codistoorderid)->getFirstItem();
		
		$orderstatus = $order->getState();
		$ordercontent = $xml->entry->content->children('http://api.ezimerchant.com/schemas/2009/');
		$ebaysalesrecordnumber = $ordercontent->ebaysalesrecordnumber[0];

		$freightcarrier = 'Post';
		$freightservice = 'Freight';
		$freighttotal =  0;
		$freighttotalextax =  0;
		$taxpercent =  0;
		$taxrate =  1;

		foreach($ordercontent->orderlines->orderline as $orderline)
		{
			if($orderline->productcode[0] == 'FREIGHT')
			{
				$freighttotal += floatval($orderline->linetotalinctax[0]);
				$freighttotalextax += floatval($orderline->linetotalextax[0]);
				$freightservice = $orderline->productname[0];
			}
		}
		
		$order->setShippingDescription($freightservice);
		$order->setBaseShippingAmount($freighttotal);
		$order->setShippingAmount($freighttotal);
		
		if($freighttotalextax != 0) {
			$taxrate = floatval($freighttotal / $freighttotalextax);
			$taxpercent = (($freighttotal / $freighttotalextax) -1) * 100;
		}

		$basegrandtotal = $order->getGrandTotal();
		$subtotal = $order->getSubtotal();
		
		$order->setBaseSubtotalInclTax($subtotal + $freighttotal);
		$order->setGrandTotal($subtotal + $freighttotal);
		$order->setBaseGrandTotal($subtotal + $freighttotal);
		$order->setBaseShippingTaxAmount($taxpercent);


		/* cancelled, processing, captured, inprogress, complete */
		if($ordercontent->orderstate == 'captured' && ($orderstatus!='pending' || $orderstatus!='new')) {
			$order->setState(Mage_Sales_Model_Order::STATE_NEW, true);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is pending payment");
		}
		if($ordercontent->orderstate == 'cancelled' && $orderstatus!='canceled') {
			$order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber has been cancelled");
		}
		if(($ordercontent->orderstate == 'inprogress' || $ordercontent->orderstate == 'processing') && $orderstatus!='processing') {
			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is in progress");
		}
		if($ordercontent->orderstate == 'complete' && $orderstatus!='complete') {
			$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_COMPLETE);
			$order->setData('state', Mage_Sales_Model_Order::STATE_COMPLETE);
			$order->addStatusToHistory($order->getStatus(), "eBay Order $ebaysalesrecordnumber is complete");
		}
		
		if($ordercontent->paymentstatus == 'paid') {
			$order->setBaseTotalPaid($basegrandtotal);
			$order->setTotalPaid($basegrandtotal);
			$order->setBaseTotalDue('0');
			$order->setTotalDue('0');
			$order->setDue('0');
		}
		
		$order->setMethod('ebaypayment');
		
		$order->save();
		
		$response = $this->getResponse();
		$response->setBody(print_r($ordercontent));
	}
	
	private function getRegionCollection($countryCode)
	{
		$regionCollection = Mage::getModel('directory/region_api')->items($countryCode);
		return $regionCollection;
	}
}
?>