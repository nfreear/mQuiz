<?php
include_once("../config.php");
$PAGE = "newquiz";

$submit = optional_param("submit","",PARAM_TEXT);

$title = optional_param("title","",PARAM_HTML);
$quizdraft = optional_param('quizdraft',0,PARAM_INT);
$description = optional_param("description","",PARAM_TEXT);
$tags = optional_param("tags","",PARAM_TEXT);
$noquestions = optional_param("noquestions",3,PARAM_INT);

if ($submit != ""){
	
	$savequiz = true;
	// checks on content, title, quizzes and responses
	// check title
	if($title == ""){
		array_push($MSG, getstring("quiz.new.error.notitle"));
		$savequiz = false;
	}
	// check at least 1 question
	// and at least one response for each question
	$noquest = 0;
	for ($q=1;$q<$noquestions+1;$q++){
		$ref = "q".($q);
		$questiontitle = optional_param($ref,"",PARAM_HTML);
		if($questiontitle != ""){
			$noquest++;
		}
		$noresponses = 0;
		for ($r=1;$r<5;$r++){
			$rref = "q".($q)."r".($r);
			$responsetitle = optional_param($rref,"",PARAM_HTML);
			if($responsetitle != ""){
				$noresponses++;
			}
		}
		if ($questiontitle == "" && $noresponses > 0){
			array_push($MSG, "You must enter a question for Q".$q);
			$savequiz = false;
		}
		
		// check a score has been entered for each question
		if($questiontitle != ""){
			$tempscore = 0;
			for ($r=1;$r<5;$r++){
				$mref = "q".($q)."m".($r);
				$score= optional_param($mref,0,PARAM_INT);
				$tempscore += $score;
			}
			if($tempscore == 0 && $noresponses>0){
				array_push($MSG, "You need to allow a non-zero score for Q".$q);
				$savequiz = false;
			}
		}
	}
	
	if($noquest == 0){
		array_push($MSG, "You must enter at least one question");
		$savequiz = false;
	}
	
	
	if ($savequiz){
		// create the quiz object
		$quizid = $API->addQuiz($title,$quizdraft,$description);
		
		$API->setProp('quiz',$quizid,'generatedby','mquiz');
		$API->updateQuizTags($quizid, $tags);
		
		$quizmaxscore = 0;
		// create each question
		for ($q=1;$q<$noquestions+1;$q++){
			$ref = "q".($q);
			$questiontitle = optional_param($ref,"",PARAM_HTML);
			if($questiontitle != ""){
				$questionid = $API->addQuestion(addslashes($questiontitle));
				$API->addQuestionToQuiz($quizid,$questionid,$q);
				$questionmaxscore = 0;
				$rcount = 0;
				// create each response
				for ($r=1;$r<5;$r++){
					$rref = "q".($q)."r".($r);
					$mref = "q".($q)."m".($r);
					$responsetitle = optional_param($rref,"",PARAM_HTML);
					$score= optional_param($mref,0,PARAM_INT);
					if($responsetitle != ""){
						$responseid = $API->addResponse($responsetitle,$score);
						$API->addResponsetoQuestion($questionid,$responseid,$r);
						$questionmaxscore += $score;
						$rcount++;
					}
				}
				
				//set question type
				if($rcount == 0){
					$API->setProp('question', $questionid, 'type', 'info');
				} else {
					$API->setProp('question', $questionid, 'type', 'multichoice');
				}
				
				
				//set max score for question
				$API->setProp('question', $questionid, 'maxscore', $questionmaxscore);
				
				$quizmaxscore += $questionmaxscore;
			}
		}
	
		// set the maxscore for quiz
		$API->setProp('quiz', $quizid, 'maxscore', $quizmaxscore);
		
		$q = $API->getQuizById($quizid);
		
		// store JSON object for quiz (for caching)
		$json = json_encode($API->getQuizObject($q->ref));
		$API->setProp('quiz', $quizid, 'json', $json);
		
		// send mail to owner
		$m = new Mailer();
		$m->sendQuizCreated($USER->email,$USER->firstname, $title, $q->ref);
		header(sprintf("Location:  %squiz/options.php?qref=%s&new=true",$CONFIG->homeAddress, $q->ref));
		die;
	} 
}
include_once("../includes/header.php");

?>
<h1><?php echo getstring("quiz.new.title"); ?> or <a href="import/index.php">enter in GIFT format</a></h1>
<?php 
if(!empty($MSG)){
	echo "<div class='warning'><ul>";
	foreach ($MSG as $err){
		echo "<li>".$err."</li>";
    }
    echo "</ul></div>";
}
?>

<div id="quizform">

<form method="post" action="">
		<div class="formblock">
			<div class="formlabel"><?php echo getstring('quiz.new.quiztitle'); ?></div>
			<div class="formfield">
				<input type="text" name="title" value="<?php echo htmlentities($title); ?>" size="60"/><br/>
			</div>
		</div>
		<div id="options" class="formblock">
			<div class='formlabel'>&nbsp;</div>
			<div class='formfield'>
				<input type="checkbox" name="quizdraft" value="1"
				<?php 
					if($quizdraft == 1){
						echo "checked='checked'";
					}
				?>
				/> Save as draft only
			</div>
		</div>
		<div class="formblock">
			<div class='formlabel'>Description<br/><small>(optional)</small></div>
			<div class='formfield'>
				<textarea name="description" cols="80" rows="3" maxlength="300"><?php echo $description; ?></textarea><br/>
				<small>Max 300 characters, no HTML</small>
			</div>
		</div>
		<div class="formblock">
			<div class="formlabel">Tags</div>
			<div class="formfield">
				<input type="text" name="tags" value="<?php echo $tags; ?>" size="60"/><br/>
				<small>comma separated</small>
			</div>
		</div>
		<div class="formblock">
			<h2><?php echo getstring("quiz.new.questions"); ?></h2>
		</div>
		<div id="questions">
			<?php 
				for($q=1; $q<$noquestions+1;$q++){
					$ref = "q".($q);
					$questiontitle = optional_param($ref,"",PARAM_TEXT);
			?>
				<div class="formblock">
					<div class="formlabel"><?php echo getstring('quiz.new.question'); echo " "; echo $q; ?></div>
					<div class="formfield">
						<textarea name="q<?php echo $q; ?>" cols="80" rows="3" maxlength="300"><?php echo $questiontitle; ?></textarea>
						<div class="responses">
							<div class="responsetext">Possible responses</div>
							<div class="responsescore">Score</div>
							<?php 
								for($r=1; $r<5;$r++){ 
									$rref = "q".($q)."r".($r);
									$mref = "q".($q)."m".($r);
									$responsetitle = optional_param($rref,"",PARAM_TEXT);
									$score= optional_param($mref,0,PARAM_INT);
							?>
								<div class="responsetext"><input type="text" name="<?php printf('q%dr%d',$q,$r); ?>" value="<?php echo htmlentities($responsetitle); ?>" size="40"></input></div>
								<div class="responsescore"><input type="text" name="<?php printf('q%dm%d',$q,$r); ?>" value="<?php echo $score; ?>" size="5"></input></div>
							<?php 
								}
							?>
						</div>
					</div>
				</div>
			<?php 
				}
			?>
			
	
		</div>
		<div class="formblock">
			<div class="formlabel">&nbsp;</div>
			<div class="formfield"><input type="button" name="addquestion" value="<?php echo getstring("quiz.new.add"); ?>" onclick="addQuestion()"/></div>
		</div>
		<div class="formblock">
			<div class="formlabel">&nbsp;</div>
			<div class="formfield"><input type="submit" name="submit" value="<?php echo getstring("quiz.new.submit.button"); ?>"></input></div>
		</div>
		<input type="hidden" id="noquestions" name="noquestions" value="2"/>

</form>
</div>
<?php 

	include_once("../includes/footer.php");
?>