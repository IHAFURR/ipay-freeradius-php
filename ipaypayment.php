<?php

/* 
 * Файл генерации оплаты. На входе требует login, password, amount - POST запросом.
 * Выполняеться проверка логина и пароля в базе freeradiusa.radcheck. 
 * Если все ок формируеться заказ на оплату в сервисе IPAY.UA. Заказ формируеться
 * с помощью файла php, который предоставляет API для работы из сервисом ipay.ua.
 * класс iPay вы можете загрузить по ссылке https://ipay.ua/ipay.class.zip
 * 
 */

//include file
include './ipay.php';
//ipay variables
$ipay_MCH_ID = ;
$ipay_MKEY = '';
$ipay_SKEY = '';
$ipay_goodUrl = "";
$ipay_badUrl = "";
$ipay_mode = "test"; //mode of transaction test/real.
//$ipay_mode = "real";
//$ipay_currency = "UAH"; //by default UAH, we dont need to change
//$ipay_type = "11"; //by default 11, we dont need to change
$ipay_description = "Оплата услуг"; //description
$ipay_info = ""; // JSON object. Будем генерировать id заказа и добавим в эту секцию.

// user variable
//$user_status = FALSE; // user_status, if exist in user_db_config TRUE else FALSE
$user_amount = "0";
$user_login = "";
$user_payment_status = 0; 

//DB - freeradius DB
$db_host = ""; // db host
$db_name = ""; // db name
$db_login = ""; // db login
$db_pass = ""; // db password

$user_table = "users"; //user table
$payment_table = "payment"; //payment table
$transaction_table = "transactions"; //transactions table

$post_error = FALSE;

//разбираемся с POST данными 
if (!isset($_POST["login"])){
    $post_error = TRUE;
}

if (!isset($_POST["password"])){
    $post_error = TRUE;
}

if (!isset($_POST["amount"])){
    $post_error = TRUE;
}

//роверяем что бы не было пустых полей
if ($post_error === FALSE) {
    $user_amount = $_POST["amount"];
    $user_login = $_POST["login"];
    $user_password = $_POST["password"];
} else {
    print ("<script>location.href='http://somesite.com/'</script>");
}

// checking user in user_db_config
$user_id = getUserId($user_login,$user_password);
//$user_id = getUserId('someuser2', 'password123');
//checking answer, if >= 1, then all ok, else error occurred
if ($user_id < 1) {
    //error
    printAnsw("index.html", "Неверно указаны логин или пароль,", "Ok");            
    exit();
}

//Если пользователь есть в базе, и пароли совпадают, выполняем оплату услуг
if ($user_id > 0) {           
    createPayment($user_id, $user_amount);
}


//functions

//Функция проверяет есть ли пользователь в базе.
// 0, возникла ошибка при подключении к базе
// любое другое число являеться ответом        
function getUserId($login, $password){
    //db 
    global $db_host,$db_name, $db_login, $db_pass, $user_table;  
    //return id
    $return_id = 0;

    //hashing password in sha256
    $hash_password = hash('sha1', $password);
//    $hash_password = $password;
    
    //trying to connect to db
    $connection = new mysqli($db_host, $db_login, $db_pass, $db_name);
    if ($connection->connect_error) {
        //if error return 0, will return to main page
        return 0;
    }
    
    $getUserId = "SELECT id FROM $user_table WHERE users = '$login' AND password = '{sha}$hash_password'";
    if ($connection->query($getUserId) != NULL){ //we have result
        $sql_result = $connection->query($getUserId);
        if (mysqli_num_rows($sql_result) === 1) {
            $idrow = $sql_result->fetch_row();
            $return_id = $idrow[0];
        }else {
            //if there no such user returtn -1
            $return_id = 0;
        }
    } else {
        //error occured
        $return_id = 0;
    } 
    // close mysql connection to prevent memory leaks
    $connection->close();
    return $return_id;
}

//Функция создает екземпляр класа iPay. Выполняем запрос
//где   $order_id - номер заказа, $amount - сума к оплате
function createPayment($user_id, $amount){

    //global variables
    global $ipay_MCH_ID, $ipay_MKEY, $ipay_SKEY, $ipay_goodUrl, $ipay_badUrl, $ipay_mode, $user_payment_status, $order_id, $description, $user_login;
    
    //Умножаем суму на 100, для отображения в формате 0000
    $ipay_amount = 100 * $amount;
    
    //order_id, создаем order_id с помощью функции createOrderID
    $order_id = createOrderID($user_id);
    
    //creating ipay class instance for creating payment request
    $iPay_crtPayment = new iPay($ipay_MCH_ID, $ipay_MKEY, $ipay_SKEY);
    $iPay_crtPayment->set_urls($ipay_goodUrl, $ipay_badUrl);
    $iPay_crtPayment->set_mode($ipay_mode);
    $iPay_crtPayment->set_transaction($ipay_amount, "wicamp.net", "{\"order_id\":"."\"".$order_id."\",\"university\":\"wicamp\""."}");  
    // Выполняем запрос на создание платежа, если пришел ответ обрабатываем его. 
    // Нам необходимо достать url. Пользователя перенапрвляем по url.
    $iPay_request = $iPay_crtPayment->create_payment();	
    try {
   	 $xml_result = new SimpleXMLElement($iPay_request);
         //Если мы получили ответ переходим на страницу оплаты
         //усли нет, то говорим пользователю об ошибке, и повтрной попытке    
    	if (($xml_result->url) != '') {
	//заносим все в базу для учета
	//Если все оплачено, пользователь увидет даные о его балансе и времени
        setcookie("user_id", $user_id, time() + 3600);
        setcookie("user_name", $user_login, time() + 3600);

    	startPayment($order_id, $user_id, $user_payment_status);
    	print "<script>location.href='".$xml_result->url."'</script>";
    	} else {
        //Выполняем действия при ошибеке
	    printAnsw("index.html", "Упс, произошла ошибка при оплате, поробуйте выполнить оплату позже.", "OK");
    	}
    } catch (Exception $exc) {
	//error
	printAnsw("index.html", "К сажалению сервис оплаты времено не доступен, попробуйте повторить попытку позже.", "OK");
    }	
}

//Логируем все действия по оплате в базе
//Функция создает запись о начале оплаты в бд 
function startPayment($order_id, $user_id, $pay_status){
    global $db_host,$db_name, $db_login, $db_pass, $transaction_table;
    
     //trying to connect to db
    $connection = new mysqli($db_host, $db_login, $db_pass, $db_name);
    if (!$connection->connect_error) {   
        $createPay = "INSERT INTO $transaction_table (user_id, order_id, pay_status) values ('$user_id', '$order_id', '$pay_status')";
        if ($connection->query($createPay) != NULL) {     
            $connection->close();
        } else {        
	    printAnsw("index.html", "Произошла ошибка при попытке оплаты, попробуйте повторить попытку позже либо обратитесь к администратору.", "OK");
        }
    }    
}

//Создаем уникальный идентификатор для учета транзакций 
function createOrderID($user_id){
    return "m-wicamp-".$user_id."-".time()."-".rand();
}

function printAnsw($action, $title, $btn){
        print "<html>";
        print "<head>";
        print "<title>Wicamp.net (Wi-Fi Campus Net)</title>";
        print "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\">";
        print "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">";
        print "<link rel=\"stylesheet\" type=\"text/css\" href=\"css/bootstrap.css\" media=\"screen\" />";
        print "</head>";
        print "<body>";
            print "<br>";
            print "<br>";
            print "<div class=\"container-fluid\">";
                print "<div class=\"text-center text-info\">";
                    print "<span><b>".$title."</b></span>";
                print "</div>";
                print "<br>";
                print "<form action=\"".$action."\">";
                    print "<div class=\"text-center\">";
                        print "<input class=\"btn btn-primary\" type=\"submit\" value=\"".$btn."\" />";
                    print "</div>";
                print "</form>";
            print "</div>";
        print "</body>";
        print "</html>";
}

