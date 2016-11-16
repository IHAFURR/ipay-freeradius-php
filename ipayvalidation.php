<?php

/*
 *Файл проверяет ответ от сервиса iPay.ua
 *На вход принимаеться POST запрос с файлом XML. "данные будут переданы методом POST через поле xml, в котором будет содержаться оповещение"
 *проверяеться наличие файла XML, если файо есть  
 *делаеться проверка поля SIGN, если они совпадают обрабатываем дальше
 *если нет, логируем все что пришло
 *если ответ содержит положительный результат оплаты, проверяем, не повторный ли это ответ:
 *"ВНИМАНИЕ! Возможна ситуация, когда оповещения по одному платежу поступят Вам
 *несколько раз, это может быть связано с невозможностью получения подтверждения о
 *получении оповещения с Вашей стороны. В таких случаях Вам необходимо корректно
 *обрабатывать такие ситуации и не допускать обработки одного платежа несколько раз."   
 *После проверки либо обновляем информацию по платежу, либо логируем повторное получение ответа
 */

// Переменые которые нам необходимы для работы
// 
//XML переменные которые могут быть в XML ответе от iPay.ua
$ipayXML = null;
$ipayXMLPaymentID = ""; // общий контейнер, содержит идентификатор платежа [id]
$ipayXMLIdentID = ""; //уникальный идентификатор платежа, используется в формировании web-ссылки. [ строка, 40 байт ]
$ipayXMLStatus = ""; //статус платежа, 5 - ok, 6 - error  [ целочисленное ]
$ipayXMLAmount = ""; //общая сумма платежа, сумма всех транзакций и комиссии в копейках.[ целочисленное ]
//$ipayXMLCurrency = ""; //3х буквенных код валюты платежа [ строка, 3 байта ]
$ipayXMLTimestamp = ""; //дата проведения последней операции по платежу, формат UNIX-timestamp. [ целочисленное ]
//$ipayXMLTransactions = ""; //содержит блоки типа «transaction».
$ipayXMLTransactionID0 = ""; //содержит данные об одной транзакции. Атрибут [ id ]–идентификатор транзакции в системе iPay. [ целочисленное ]
$ipayXMLTransactionID1 = ""; //содержит данные об одной транзакции. Атрибут [ id ]–идентификатор транзакции в системе iPay. [ целочисленное ]
//$ipayXMLTransMchId = ""; //идентификатор мерчанта в системе, в пользу которого совершается транзакция. [ целочисленное ]
//$ipayXMLTransSrvId = ""; //идентификатор услуги по которой совершается транзакция.[ целочисленное ]
//$ipayXMLTransType = ""; //тип транзакции. [ 10 (авторизация) , 11 (списание), 12 (отмена) ]
$ipayXMLTransInfo = ""; //код от куда мы достаем userID, и обновляем щет пользователя в базе по userID
$ipayXMLSalt = ""; //строка, сгенерированная из текущего времени в микросекундах по алгоритму SHA1.
$ipayXMLSign = ""; //строка, сгенерированная из строки <salt> и секретного ключа API по алгоритму SHA512.    
$XMLOrderID = ""; //id платежа, находиться в ipayXMLTransInfo, необходим для проверки дубкликации платежа
$ipayOKStatus = "5";
$userID = "";
$paymentStatus = "";
$OKPaymentStatus = "1";
$errorPaymentStatus = "-1";

//DB data
$dbHost = "";
$dbName = "";
$dbLogin = "";
$dbPassword = "";
$dbUserTable = "";
$dbLogTable = "";
$dbExpTable = "";

//переменые мерчанта которые выдал iPay.ua необходимы для проверки xml sign
$ipay_MCH_ID = ;
$ipay_MKEY = '';
$ipay_SKEY = '';
$incr_30 = 30;
//current date
$time = new DateTime();

//LOG
$logPath = "/var/log/ipay";
$fileTime = $time->format('dmY');
$logFile = "ipay".$fileTime.".log";

//if we have sql error write all data to file
#$spareFilePath = "/var/log/ipay/";
#$spareFilePath = getcwd(); //for test purpose
#$spareFile = 'spare'.$fileTime.log;

//Getting xml file if exist
if (!isset($_POST['xml'])) {	
    writeLog('Empty POST', ' ');
    printResponse(0);
}

$response = $_POST['xml'];
//echo "\nResponse\n".$response;
try {
    $xml = new SimpleXMLElement($response); 
    $f = fopen($logPath.'/xml/ipayXML'.$time->format('Y-m-d-His').'.xml', 'w+');
    fwrite($f, utf8_decode($xml->asXML()));
    fclose($f);

} catch (Exception $exc) {
    //error if cant handle xml file
    printResponse(0);
}

//check if salt and sign != null, if yes error
if ((isset($xml->salt)) && (isset($xml->sign))) {
    $ipayXMLSalt = $xml->salt;
    $ipayXMLSign = $xml->sign;  
} else {
    //we have empty salt or sign, print error
    writeLog('Sing or salt error', 'salt: '.$xml->salt.'  sing:  '.$xml->sign.' ');
    printResponse(0);
}
   
//checking sign from ipay, if not equal error
if (checkSign($ipayXMLSalt, $ipayXMLSign)) {
    //if sing is correct, fill ipay variables with data form xml file
    $ipayXMLStatus = $xml->status;      
    $ipayXMLPaymentID =  $xml->attributes()->id;
    $ipayXMLIdentID = $xml->ident; 
    $ipayXMLAmount = $xml->amount;
    $ipayXMLTimestamp = $xml->timestamp;
    $ipayXMLTransactionID0 = $xml->transactions->transaction[0]->attributes()->id;
    $ipayXMLTransactionID1 = $xml->transactions->transaction[1]->attributes()->id;
    $ipayXMLTransInfo = $xml->transactions->transaction[1]->info;
    $jsonInfo = json_decode($ipayXMLTransInfo);
    $XMLOrderID = $jsonInfo->{'order_id'};
    //get array of data from orderID
    $splitArray = preg_split('/\-/', $XMLOrderID);
    //get userID
    $userID =  $splitArray[2];
    
    //check status if 5 - ok, if other error (ipay API)
    if (strcmp($ipayXMLStatus, $ipayOKStatus) === 0) {
	#echo $ipayXMLStatus." ok status: ".$ipayOkStatus."\n";
        //if we have an open payment update it and close, also update user amount in user table
        if (checkPayment($XMLOrderID)) {
            #echo "\nall ok we done it  ". "{\"order_id\":"."\"".$XMLOrderID."\""."}";
            //updating log data in db and closing payment also updating user amount
            if (updateDBLogData(
                    $XMLOrderID, $OKPaymentStatus,
                    $ipayXMLPaymentID, $ipayXMLIdentID,
                    $ipayXMLStatus, $ipayXMLAmount,
                    $ipayXMLTimestamp, $ipayXMLTransactionID0,
                    $ipayXMLTransactionID1, $ipayXMLTransInfo
                    ) != TRUE) {
                printResponse(0);
            } else {
                writeLog('OK, updated, closed', $userID.';'.$XMLOrderID.';'.$OKPaymentStatus.';'
                        .$ipayXMLPaymentID.';'.$ipayXMLIdentID.';'.$ipayXMLAmount);
                #echo 'log updated <br>'; 
                if (updateUserAmoun($userID, $ipayXMLAmount) != TRUE) {
                    writeLog("Log table was updated", "payment table error, update user: ".$userID.'  orderId: '.$XMLOrderID);     
                } else {
                    writeLog('OK, amount updated', $userID.';'.$ipayXMLAmount);
                }
            }
        } else {
            //we already have close such payment
            writeLog('OK, closed', $userID.';'.$XMLOrderID.';'.$OKPaymentStatus.';'
                        .$ipayXMLPaymentID.';'.$ipayXMLIdentID.';'.$ipayXMLAmount);             
	printResponse(0);
        }
        printResponse(1);
    } else {
        //if status == 6 error write to db
        if (updateDBLogData(
            $XMLOrderID, $errorPaymentStatus,
            $ipayXMLPaymentID, $ipayXMLIdentID,
            $ipayXMLStatus, $ipayXMLAmount,
            $ipayXMLTimestamp, $ipayXMLTransactionID0,
            $ipayXMLTransactionID1, $ipayXMLTransInfo
            ) != TRUE) {
            printResponse(0);
        } else {
            writeLog('Error, updated, closed', '  '.$userID.';'.$XMLOrderID.';'.$errorPaymentStatus.';'
                    .$ipayXMLPaymentID.';'.$ipayXMLIdentID.';'.$ipayXMLAmount);
            printResponse(1);
        }
    }

} else {
    //sign is incorrect so it is not our payment acount, print error to ipay.ua
    writeLog('Error, wrong data', '   '.$ipayXMLSalt.';'.$ipayXMLSign.';'.$ipayXMLTransInfo); 	
    printResponse(0);
}

//Function
    //check xml sign
    function checkSign($ipaySalt, $ipaySign) 
    {
        global $ipay_SKEY;
        //"строка, сгенерированная из строки <salt> и секретного ключа API по алгоритму SHA512"
        $sign = hash_hmac('sha512', $ipaySalt, $ipay_SKEY);
        //if str1 and str2 are equal retrun true else false 
        if (strcmp($sign, $ipaySign) === 0 ) {
            return TRUE;
        } else {	
            return FALSE;
        }
    }
    
    //check if we have already such payment in db boolean
    //if we have error, or payment already closed return false
    //if we have payment and it still open return true
    function checkPayment($orderID)
    {
        global $dbHost,$dbName, $dbLogin, $dbPassword, $dbLogTable;  
        //trying to connect to db
        $connection = new mysqli($dbHost, $dbLogin, $dbPassword, $dbName);

        if ($connection->connect_error) {
            return FALSE;
        }

        $checkPayment = "SELECT pay_status FROM $dbLogTable WHERE order_id = '$orderID'";
                        
        if ($sqlResult = $connection->query($checkPayment)){ //we have result                    
            if (mysqli_num_rows($sqlResult) != 0) {
                $statusID = $sqlResult->fetch_row();
                $statusID = $statusID[0];                
                if ($statusID == 0) {
                    return TRUE;
                }                 
            } else {  
                return FALSE;
            }
        } else {
            return FALSE;
        } 
        // close mysql connection to prevent memory leaks
        $connection->close(); 
    }
    
    //print responce to ipay.ua
    function printResponse($status)
    {
        switch ($status) {
            case 0; //we have wrong sign
                $response = 'PAYMENT FAIL';
                header('HTTP/1.1 500');
                header('Status: 500');
                echo $response;
                break;
            case 1: //all worked well and we made all we need
                $response = 'PAYMENT OK';
                header("HTTP/1.1 200 OK");
                header('Status: 200 OK');
                echo $response;
                break;
        }
    }
    
    //if we dont have such payment update data in db
    function updateDBLogData(
                $orderID,
                $paymentStatus,
                $payID,
                $identID,
                $ipayStatus,
                $amount,
                $timestamp,
                $trID1,
                $trID2,
                $info
            ){
                global $dbHost, $dbName, $dbLogin, $dbPassword, $dbLogTable;  
                //trying to connect to db
                $connection = new mysqli($dbHost, $dbLogin, $dbPassword, $dbName);
                
                if ($connection->connect_error) {
                    return FALSE;
                }

                $updateUserLog = "UPDATE $dbLogTable SET pay_status = '$paymentStatus',"
                        ." payment_id = '$payID', ident_id = '$identID',"
                        ." status = '$ipayStatus', amount = '$amount',"
                        ." timestamp = '$timestamp', transaction_id1 = '$trID1',"
                        ." transaction_id2 = '$trID2', info = '$info'"
                        ." WHERE order_id = '$orderID'";

                if ($connection->query($updateUserLog) != FALSE) {
                    return TRUE;
                } else {
                    return FALSE;
                }
                
                $connection->close();
            }    
    
    //update user amount in user db
    function updateUserAmoun($user_id, $amount)
    {
        global $dbHost,$dbName, $dbLogin, $dbPassword, $dbUserTable;
        //converting amount to dauble
        $amount = $amount/100.0; 
        //trying to connect to db
        $connection = new mysqli($dbHost, $dbLogin, $dbPassword, $dbName);
                
        if ($connection->connect_error) {
            return FALSE;
        }
                
        $updateUserAmount = "Update $dbUserTable SET amount = amount + $amount WHERE user_id = '$user_id'";
                
        if ($connection->query($updateUserAmount) != FALSE){	
	    if(updateExpDate($user_id, $amount)){
                return TRUE;
	    } else {
		writeLog("UpdateAmount", "Cant update user exp date. id = $user_id, amount \ $amount");
		return FALSE;
	    }	
        } else {
	    writeLog("UpdateAmount", "Cant update user exp date. id = $user_id, amount \ $amount");
            //write to file that will be executed later 
            return FALSE;
        }
        $connection->close();   
    }

    function updateExpDate($userId, $days){
	
	$currentDate = new DateTime();
	$updateDate = "";
	$lastDate = "";
	//print "Current date: ".$currentDate->format('M j Y H:i:s')."<br>";
	global $dbHost, $dbName, $dbLogin, $dbPassword, $dbExpTable, $incr_30;
	$connection = new mysqli($dbHost, $dbLogin, $dbPassword, $dbName);
        if ($connection->connect_error) {
            return FALSE;
        }

        $getTime = "SELECT time_to FROM $dbExpTable WHERE user_id = '$userId'";
                        
        if ($sqlResult = $connection->query($getTime)){
            $row = mysqli_fetch_row($sqlResult);
            //print "row: ".(string)$row[0];
            if (!$row[0]) {
                //print log
		writeLog("UpdateExpDate", "Cant find $userId in $dbExpTable");
                return FALSE;
            } else {
                try{
                    //get exp. date from sql
                    $lastDate = new DateTime((string)$row[0]);
		    $dateBeforeUpd;            
                    //Проверяем, есть ли у пользователя время, если нет, тогда
                    //прибавляем все время к $currentDate, в ином случае к тому что было
                    if ($lastDate > $currentDate){
                        $updateDate = $lastDate;
			$dateBeforeUpd = $lastDate;
                        date_add($updateDate, date_interval_create_from_date_string("$incr_30 days"));
                        //print "New date from lastDate: ".$updateDate->format('M j Y H:i:s')."<br>";
                    } else {
                        $updateDate = $currentDate;
			$dateBeforeUpd = $currentDate;
                        date_add($updateDate, date_interval_create_from_date_string("$incr_30 days"));
                        //print "New date from currentDate: ".$updateDate->format('M j Y H:i:s')."<br>";    
                    }

		    writeLog("UpdateExpDate", "User date, $userId, before updating ".$currentDate->format('M j Y H:i:s'));	                    
                    //print "exp time: ".$updateDate->format('M j Y H:i:s')." time to: ".$updateDate->format('Y-m-d H:i:s');
                    $updateSQLTime = "UPDATE users_exp SET exp_time = '".$updateDate->format('M j Y H:i:s').
                            "', time_to = '".$updateDate->format('Y-m-d H:i:s')."' where user_id = '$userId'";
                    
                    if ($connection->query($updateSQLTime) === TRUE){
                        //print "User, $userId, date was updated!! <br>".$connection->connect_error;
			writeLog("updateExpDate", "User, $userId, date was updated: ".$updateDate->format('M j Y H:i:s')); 
			return TRUE;
                    } else {
			writeLog("UpdateExpDate", "Cant update date where table = $dbExpTable, user_id = $userId, time_to = ".$updateDate->format('Y-m-d H:i:s'));
                        //print "<br> Sorry, but we have error: $userId<br>".$connection->connect_errno;
			return FALSE;
                    }     
                    
                } catch (Exception $e){
		    writeLog("UpdateExpDate", "Cant convert to DateTime value ".(string)$row[0]." from table $dbExpTable and user_id $userId"); 
		    return FALSE;
                }    
            }    
        } else {
            //print to log
	    writeLog("UpdateExpDate", "Cant connect to $dbHost");
	    return FALSE;
        }
    }   
   
    //write log to file
    function writeLog($logStatus, $string)
    {
        global $logPath, $logFile, $time;
        //write only
        $fp = fopen($logPath.'/'.$logFile, "a");
        $log = $time->format('d-m-Y/H:i')."  $logStatus  $string\n";
        fwrite($fp, $log);
        fclose($fp);
    }


