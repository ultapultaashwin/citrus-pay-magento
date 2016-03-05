<?php 

require 'Zend/Config/Ini.php';

class CitrusPay_Moto_IndexController extends Mage_Core_Controller_Front_Action
{
	public function indexAction()
	{
		$this->getResponse()->setBody($this->getLayout()->createBlock('moto/form_pay')->toHtml());
	}
	
	private static function _generateHmacKey($data, $apiKey=null){
		$hmackey = Zend_Crypt_Hmac::compute($apiKey, "sha1", $data);
		return $hmackey;
	}

	public function paymentAction()
	{
		$txnid = "";
		$txnrefno = "";
		$TxStatus = "";
		$txnmsg = "";
		$firstName = "";
		$lastName = "";
		$email = "";
		$street1 = "";
		$city = "";
		$state = "";
		$country = "";
		$pincode = "";
		$mobileNo = "";
		$signature = "";
		$reqsignature = "";
		$data = "";
		$flag = "dataValid";
		$respdata = "";
		
		$order = Mage::getModel('sales/order');
		$orderid=-1;
		
		$apiKey = Mage::getStoreConfig('payment/moto/apikey');
		
		if($this->getRequest()->isPost())
		{
			$signatureFlag = Mage::getStoreConfig('payment/moto/matchSignature');
			
			$postdata = $this->getRequest()->getPost();
			
			$txnid = $postdata['TxId'];
			$data .= $txnid;
			$respdata .= "<br/><strong>Citrus Transaction Id: </strong>".$txnid;
			
			$orderid=$txnid;
			$order->loadByIncrementId($orderid);
			
			$TxStatus = $postdata['TxStatus'];
			$data .= $TxStatus;
			$respdata .= "<br/><strong>Transaction Status: </strong>".$TxStatus;
			
			$amount = $postdata['amount'];
			$data .= $amount;
			$respdata .= "<br/><strong>Amount: </strong>".$amount;
			
			$pgtxnno = $postdata['pgTxnNo'];
			$data .= $pgtxnno;
			$respdata .= "<br/><strong>PG Transaction Number: </strong>".$pgtxnno;
			
			$issuerrefno = $postdata['issuerRefNo'];
			$data .= $issuerrefno;
			$respdata .= "<br/><strong>Issuer Reference Number: </strong>".$issuerrefno;
			
			$authidcode = $postdata['authIdCode'];
			$data .= $authidcode;
			$respdata .= "<br/><strong>Auth ID Code: </strong>".$authidcode;
			
			$firstName = $postdata['firstName'];
			$data .= $firstName;
			$respdata .= "<br/><strong>First Name: </strong>".$firstName;
			
			$lastName = $postdata['lastName'];
			$data .= $lastName;
			$respdata .= "<br/><strong>Last Name: </strong>".$lastName;
			
			$pgrespcode = $postdata['pgRespCode'];
			$data .= $pgrespcode;
			$respdata .= "<br/><strong>PG Response Code: </strong>".$pgrespcode;
			
			$pincode = $postdata['addressZip'];
			$data .= $pincode;
			$respdata .= "<br/><strong>PinCode: </strong>".$pincode;
			
			$signature = $postdata['signature'];
			
			$respSignature = self::_generateHmacKey($data,$apiKey);
			
			/* Suppose a Custom parameter by name Roll Number Comes in Post Parameter.
			 * then we need to retreive the RollNumber as
			* $rollNumber = $postdata['Roll Number'];
			* For other custom parameters as well this code can be used to retreive them. */
			
			if($signature != "" && strcmp($signature, $respSignature) != 0)
			{
				$flag = "dataTampered";
			}
			$txMsg = 'CitrusPay: '.$postdata['TxMsg'];
			$respdata .= "<br/><strong>Citrus Transaction Message: </strong>".$txMsg;
			$txnGateway = $_POST['TxGateway'];
			$respdata .= "<br/><strong>Transaction Gateway: </strong>".$txnGateway;
			/*$issuerCode = $_POST['issuerCode'];
			$respdata .= "<br/><strong>Issuer Code: </strong>".$issuerCode;
			$paymentMode = $_POST['paymentMode'];
			$respdata .= "<br/><strong>Payment Mode: </strong>".$paymentMode;
			$maskedCardNumber = $_POST['maskedCardNumber'];
			$respdata .= "<br/><strong>Card Number: </strong>".$maskedCardNumber;
			$cardType = $_POST['cardType'];
			$respdata .= "<br/><strong>Card Type: </strong>".$cardType;*/
			//Mage::log("Citrus Response received is ".$TxStatus);
			//Mage::log("Citrus Response Message is ".$txMsg);
			//Mage::log("Citrus Response signature recieved is ".$signature);
			//Mage::log("Citrus Response signature generated is ".$respSignature);
			if($TxStatus == 'SUCCESS')
			{
				if($signatureFlag == 'Y')
				{
					if($flag != "dataValid")
					{	
						$order->setState(Mage_Sales_Model_Order::STATE_NEW, true);				        
						$order->addStatusHistoryComment("Citrus Response signature does not match. You might have received tampered data")->setIsVisibleOnFront(false)->setIsCustomerNotified(false);
						$order->cancel()->save();
						Mage::getSingleton('checkout/session')->setErrorMessage("<strong>Error:</strong> Citrus Response signature does not match. You might have received tampered data");
						Mage::log("Citrus Response signature did not match ");
						$this->_redirect('checkout/onepage/failure');
					}else{
						Mage::getSingleton('core/session')->addSuccess($txMsg);
						$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
						$order->addStatusHistoryComment($txMsg)->setIsVisibleOnFront(false)->setIsCustomerNotified(false);
						$order->save();						
						$order->sendNewOrderEmail();	
						Mage::log("Citrus Response Order success..");
						$this->_redirect('checkout/onepage/success');
					}
					
				}
				else {
					Mage::log("Citrus Response - Must enable signature validation in Admin...");
				}
				
			}
			else
			{
				$order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
		        // Inventory updated 
		   	    $this->updateInventory($orderid);
				$order->addStatusHistoryComment($txMsg)->setIsVisibleOnFront(false)->setIsCustomerNotified(false);
	    		$order->cancel()->save();				
				Mage::getSingleton('checkout/session')->setErrorMessage("<strong>Error:</strong> $txMsg <br/>");
				Mage::log("Citrus Response Order canceled ..");
				$this->_redirect('checkout/onepage/failure');
			}
		}
		Mage::log("Citrus Transaction END from Citruspay");
	}
	
	public function updateInventory($order_id)
    {
  
        $order = Mage::getModel('sales/order')->loadByIncrementId($order_id);
        $items = $order->getAllItems();
		foreach ($items as $itemId => $item)
		{
		   $ordered_quantity = $item->getQtyToInvoice();
		   $sku=$item->getSku();
		   $product = Mage::getModel('catalog/product')->load($item->getProductId());
		   $qtyStock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product->getId())->getQty();
		  
		   $updated_inventory=$qtyStock + $ordered_quantity;
					
		   $stockData = $product->getStockItem();
		   $stockData->setData('qty',$updated_inventory);
		   $stockData->save(); 
			
	   } 
    }


}