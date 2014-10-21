<?
if (isset($_POST["m_operation_id"]) && isset($_POST["m_sign"]))
{				
	chdir ('../../');
	require_once('api/Simpla.php');
	$simpla = new Simpla();
	
	$sign_post = $_POST["m_sign"];
	$status = $_POST['m_status'];
	$order_id = $_POST['m_orderid'];
	$amount = $_POST['m_amount'];
	$currency_code = $_POST['m_curr'];
	$order = $simpla->orders->get_order(intval($order_id));
	if(!empty($order))
	{
		$method = $simpla->payment->get_payment_method(intval($order->payment_method_id));
		if(!empty($method))
		{
			$settings = unserialize($method->settings);
			$payment_currency = $simpla->money->get_currency(intval($method->currency_id));

			$m_key = $settings['payeer_secret'];
			$arHash = array($_POST['m_operation_id'],
				$_POST['m_operation_ps'],
				$_POST['m_operation_date'],
				$_POST['m_operation_pay_date'],
				$_POST['m_shop'],
				$_POST['m_orderid'],
				$_POST['m_amount'],
				$_POST['m_curr'],
				$_POST['m_desc'],
				$_POST['m_status'],
				$m_key);
			$sign_hash = strtoupper(hash('sha256', implode(":", $arHash)));
		
			// проверка принадлежности ip списку доверенных ip
			$list_ip_str = str_replace(' ', '', $settings['payeer_ip_list']);
			
			if ($list_ip_str != '') 
			{
				$list_ip = explode(',', $list_ip_str);
				$this_ip = $_SERVER['REMOTE_ADDR'];
				$this_ip_field = explode('.', $this_ip);
				$list_ip_field = array();
				$i = 0;
				$valid_ip = FALSE;
				foreach ($list_ip as $ip)
				{
					$ip_field[$i] = explode('.', $ip);
					if ((($this_ip_field[0] ==  $ip_field[$i][0]) or ($ip_field[$i][0] == '*')) and
						(($this_ip_field[1] ==  $ip_field[$i][1]) or ($ip_field[$i][1] == '*')) and
						(($this_ip_field[2] ==  $ip_field[$i][2]) or ($ip_field[$i][2] == '*')) and
						(($this_ip_field[3] ==  $ip_field[$i][3]) or ($ip_field[$i][3] == '*')))
						{
							$valid_ip = TRUE;
							break;
						}
					$i++;
				}
			}
			else
			{
				$valid_ip = TRUE;
			}
			
			// запись в логи если требуется
			$log_text = 
			"--------------------------------------------------------\n".
			"operation id		".$_POST["m_operation_id"]."\n".
			"operation ps		".$_POST["m_operation_ps"]."\n".
			"operation date		".$_POST["m_operation_date"]."\n".
			"operation pay date	".$_POST["m_operation_pay_date"]."\n".
			"shop				".$_POST["m_shop"]."\n".
			"order id			".$_POST["m_orderid"]."\n".
			"amount				".$_POST["m_amount"]."\n".
			"currency			".$_POST["m_curr"]."\n".
			"description		".base64_decode($_POST["m_desc"])."\n".
			"status				".$_POST["m_status"]."\n".
			"sign				".$_POST["m_sign"]."\n\n";
			
			if ($settings['payeer_log'] == 1)
			{
				file_put_contents($_SERVER['DOCUMENT_ROOT'].'/payeer_log.txt', $log_text, FILE_APPEND);
			}
	
			file_put_contents($_SERVER['DOCUMENT_ROOT'].'/payeer_log.txt', "valid=".$valid_ip, FILE_APPEND);
			if ($sign_hash == $sign_post)
			{
				if ($valid_ip)
				{
					// Нельзя оплатить уже оплаченный заказ  
					if (!$order->paid)
					{
						if ($amount >= round($simpla->money->convert($order->total_price, $method->currency_id, false), 2))
						{
							$currency = $payment_currency->code;
							if ($currency == 'RUR')
							{
								$currency = 'RUB';
							}
							if ($currency_code == $currency)
							{
								if ($status == 'success')
								{
									$simpla->orders->update_order(intval($order->id),
										array(
										'paid'=>1,
										'status'=>$settings['payeer_order_status']
									));
					  
									// Отправим уведомление на email
									$simpla->notify->email_order_user(intval($order->id));
									$simpla->notify->email_order_admin(intval($order->id));
									
									// Спишем товары  
									$simpla->orders->close(intval($order->id));
									echo $_POST['m_orderid']."|success";
									exit;
								}
							}
							else
							{
								$err = " - Error exchange";
							}
						}
						else
						{
							$err = " - Error amount of the order";
						}
					}
					else 
					{
						$err = " - Order is already paid";
					}
				}
				else
				{
					$err = " - the ip address of the server is not trusted\n";
					$err .= "   trusted ip: ".$settings['payeer_ip_list']."\n";
					$err .= "   ip of the current server: ".$_SERVER['REMOTE_ADDR'];
				}
			}
			else
			{
				$err = " - The digital signature error";
			}
		}
		else
		{
			$err = " - Unknown payment method";
		}
	}
	else
	{
		$err = " - Unknown order order";
	}
	
	echo $_POST['m_orderid']."|error";
	// оповещение на email администратора об ошибках
	if ($settings['payeer_email'] != '')
	{
		$to = $settings['payeer_email'];
		$subject = "Error payment";
		$message = "Failed to make the payment through the system Payeer for the following reasons:\n\n";
		$message .= $err;
		$message .= "\n".$log_text;
		$headers = "From: no-reply@".$_SERVER['HTTP_SERVER']."\r\nContent-type: text/plain; charset=utf-8 \r\n";
		mail($to, $subject, $message, $headers);
	}
}