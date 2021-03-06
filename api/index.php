<?php 
include_once("../config.php");

$format = optional_param("format","plain",PARAM_TEXT);

if($format == 'json'){
	header('Content-type: application/json; charset=UTF-8');
} else {
	header("Content-type:text/plain;charset:utf-8");
}

$method = optional_param("method","",PARAM_TEXT);
$username = optional_param("username","",PARAM_TEXT);
$password = optional_param("password","",PARAM_TEXT);

$response = new stdClass();

/*
 * Methods with no login required
 */

if($method == 'register'){
	$email = optional_param("email","",PARAM_TEXT);
	$passwordAgain = optional_param("passwordagain","",PARAM_TEXT);
	$firstname = optional_param("firstname","",PARAM_TEXT);
	$lastname = optional_param("lastname","",PARAM_TEXT);
	
	if ($username == ""){
		$response->error = "Enter your username";
	} else if ($email == ""){
		$response->error = "Enter your email";
	} else	if(!preg_match("/^[_a-z0-9-]+(.[_a-z0-9-]+)*@[a-z0-9-]+(.[a-z0-9-]+)*(.[a-z]{2,3})$/i", $email) ) {
		$response->error = "Invalid email address format";
	} else if (strlen($password) < 6){ // check password long enough
		$response->error = "Your password must be 6 characters or more";
	} else if ($password != $passwordAgain){ // check passwords match
		$response->error = "Your passwords don't match";
	} else if ($firstname == ""){
		$response->error = "Enter your firstname";
	} else if ($lastname == ""){
		$response->error = "Enter your lastname";
	} else {
		if($API->checkUserNameInUse($username)){
			$response->error = "Username already registered";
		} else if($API->checkEmailInUse($email)){
			$response->error = "Email already registered";
		} else {
			$API->addUser($username, $password, $firstname, $lastname, $email);
			$m = new Mailer();
			$m->sendSignUpNotification($firstname." ".$lastname);
	
			$login = userLogin($username,$password);
			$response->login = $login;
			$response->hash = md5($password);
			$response->name = $firstname." ".$lastname;
		}
	}
}


if($method == 'login'){
	$login = userLogin($username,$password);
	if($login){
		$response->login = $login;
		$response->hash = md5($password);
		$response->name = $USER->firstname." " .$USER->lastname;
		// get 10 most recent quiz results for user
		$response->results = $API->getUserRecentAttempts($USER->userid);
		
	} else {
		$response->error = "Login failed";
	}
	
}

if($method == 'search'){
	$t = optional_param("t","",PARAM_TEXT);
	if($t == ""){
		$response->error = "No search terms provided";
	} else {
		$response = $API->searchQuizzes($t);
	}
}

/*
 * Methods which allow logged in or anon users
 */
if($method == 'getquiz' 
		|| $method == 'submit'){
	if (!userLogin($username,$password,false)){
		$USER = new User($CONFIG->anonuser);
		$USER->setUsername($CONFIG->anonuser);
	}
	if($method == 'getquiz'){
		$qref = optional_param('qref','',PARAM_TEXT);
		$quiz = $API->getQuiz($qref);
		if($quiz == null){
			$response->error = "Quiz not found";
		} else if($quiz->quizdraft == 1 && !$API->isOwner($qref)){
			$response->error = "Quiz not available for download";
		} else {
			$response = $API->getQuizObject($qref);
		}
	}
	
	if($method == 'submit'){
		$content = optional_param("content","",PARAM_TEXT);
		//$response->error = "no content";
		if($content == ""){
			$response->error = "no content";
		} else {
			$json = json_decode(stripslashes($content));
			// only save results if not owner
			if(!$API->isOwner($json->qref)){
				$attemptid = saveResult($json,$username);
				if($attemptid == null){
					$response->error = "quiz not found";
				} else {
					$best = $API->getBestRankForQuiz($json->qref, $USER->userid);
					$response->rank = $API->getRankingForAttempt($attemptid);
					$response->bestrank = $best;
						
					$qa = $API->getQuizAttempt($attemptid);
					$response->next = $API->suggestNext($json->qref,$qa->score);
					$response->result = true;
				}
			} else {
				$response->result = true;
			}
		}
	}
}	

/*
* Methods with login required
*/
if ($method == "list" 
		|| $method == "suggest" 
		|| $method == "invite" 
		|| $method == "create" 
		|| $method == "tracker"){
	if (!userLogin($username,$password,false)){
		$response->login = false;
	} else {
		
		if($method == 'list'){
			$quizzes = $API->getQuizzes();
		
			$page = curPageURL();
			if(endsWith($page,'/')){
				$url_prefix = $page;
			} else {
				$url_prefix = dirname($page)."/";
			}
		
			$response = array();
			foreach($quizzes as $q){
				if(!$q->quizdraft){
					$o = array(	'qref'=>$q->qref,
									'quiztitle'=>$q->title,
									'url'=>$url_prefix."?format=json&method=getquiz&qref=".$q->qref);
					array_push($response,$o);
				}
			}
		}
		
		if($method == 'suggest'){
			$response = $API->suggestQuizzes();
		}
		
		if($method == 'invite'){
			$qref = optional_param("qref","",PARAM_TEXT);
			$emails = optional_param("emails","",PARAM_TEXT);
			$message = optional_param("message","",PARAM_TEXT);
			$response->result = $API->invite($qref,$emails,$message);
		}
		
		if($method == 'create'){
			$IMPORT_INFO = array();
			$content = optional_param("content","",PARAM_TEXT);
			$title = optional_param("title","",PARAM_TEXT);
			$quizdraft = optional_param("quizdraft","false",PARAM_TEXT);
			$description = optional_param("description","",PARAM_TEXT);
			$tags = optional_param("tags","",PARAM_TEXT);
			
			if($title == ""){
				$response->error = "No title provided";
			} else if($content == ""){
				$response->error = "No content provided";
			} else {
				$response = $API->createQuizfromGIFT($content,$title,$quizdraft,$description,$tags);
			}
		}
		
		if($method == 'tracker'){
			$content = optional_param("content","",PARAM_TEXT);
			try {
				$tracks = json_decode(stripslashes($content));
				if(is_array($tracks)){
					$count = count($tracks);
					if($count > 0){
						foreach($tracks as $t){
							if(isset($tracks->digest)){
								$digest = $tracks->digest;
							} else {
								$digest = "";
							}
							if(isset($tracks->datetime)){
								$date = $tracks->datetime;
							} else {
								$date = date('Y-m-d H:i:s');
							}
							writeToLog("info","tracker",json_encode($t),0,0,0,0,$digest,$date);
						}
						$response->result = true;
					} else {
						$response->result = false;
					}
				} else {
					
					if(isset($tracks->digest)){
						$digest = $tracks->digest;
					} else {
						$digest = "";
					}
					if(isset($tracks->datetime)){
						$date = $tracks->datetime;
					} else {
						$date = date('Y-m-d H:i:s');
					}
					
					writeToLog("info","tracker",json_encode($tracks),0,0,0,0,$digest,$date);
					$response->result = true;
				}
			} catch (Exception $e){
				$response->result = false;
			}
		}
	}
}

/*
 * Output the response
 */

echo json_encode($response);

writeToLog("info","pagehit",$_SERVER["REQUEST_URI"]." method: ".$method);
$API->cleanUpDB();

function curPageURL() {
	$pageURL = 'http';
	if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {
		$pageURL .= "s";
	}
	$pageURL .= "://";
	if ($_SERVER["SERVER_PORT"] != "80") {
		$pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
	} else {
		$pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
	}
	return $pageURL;
}

function endsWith($haystack, $needle){
	$length = strlen($needle);
	$start  = $length * -1; //negative
	return (substr($haystack, $start) === $needle);
}

function saveResult($json,$username){
	global $API;
	$newId = 0;
	try{
		if (isset($json->qref)){
			$quiz = $API->getQuiz($json->qref);
		} else {
			return false;
		}
		
		$qa = new QuizAttempt();
		$qa->quizref = $json->qref;
		$qa->username = $json->username;
		$qa->maxscore = $json->maxscore;
		$qa->userscore = $json->userscore;
		$qa->quizdate = $json->quizdate;
		$qa->submituser = $username;
		
		// insert to quizattempt
		$newId = $API->insertQuizAttempt($qa);
		
		$responses = $json->responses;
		foreach ($responses as $r){
			$qar = new QuizAttemptResponse();
			$qar->qaid = $newId;
			$qar->userScore = $r->score;
			$qar->questionRef = $r->qid;
			$qar->text = $r->qrtext;
			$API->insertQuizAttemptResponse($qar);
		}
		return $newId;
	} catch (Exception $e){
		return $newId;
	}
}
?>
