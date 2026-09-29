<?php
declare(strict_types=1);
require_once __DIR__.'/supabase_client.php'; header('Content-Type: application/json; charset=utf-8');
function bc_reg_fail(int $code,string $message): never {http_response_code($code);echo json_encode(['success'=>false,'error'=>$message],JSON_UNESCAPED_UNICODE);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')bc_reg_fail(405,'Method not allowed');
$first=trim((string)($_POST['first_name']??''));$middle=trim((string)($_POST['middle_name']??''));$last=trim((string)($_POST['last_name']??''));$username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm_password']??'');$contact=preg_replace('/\D/','',(string)($_POST['contact_number']??''));$email=strtolower(trim((string)($_POST['email']??'')));$birth=trim((string)($_POST['birthdate']??''));$gender=trim((string)($_POST['gender']??''));$extraRaw=(string)($_POST['profile_extra']??'');$extra=json_decode($extraRaw,true);if(!is_array($extra))$extra=[];
if($first===''||$last===''||$username===''||$password===''||$birth===''||$gender==='')bc_reg_fail(400,'Complete all required fields.');
if(strlen($password)<8)bc_reg_fail(400,'Password must be at least 8 characters.');if($password!==$confirm)bc_reg_fail(400,'Passwords do not match.');if(!preg_match('/^09\d{9}$/',$contact))bc_reg_fail(400,'Contact number must be 11 digits.');if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL))bc_reg_fail(400,'A valid email address is required for account verification.');
$uid='';
try{
 $phone='+63'.substr($contact,1);
 $filters=['or'=>'(username.eq.'.$username.',phone.eq.'.$phone.($email!==''?',email.eq.'.$email:'').')','select'=>'id','limit'=>'1'];
 $existing=bc_sb_rest('profiles','GET',$filters);if(!empty($existing['data']))bc_reg_fail(409,'Username or mobile number is already registered.');
 $identity=bin2hex(random_bytes(32));
 $authBody=['email'=>$email,'password'=>$password,'data'=>['username'=>$username,'first_name'=>$first,'middle_name'=>$middle,'last_name'=>$last,'phone'=>$phone]];
 $auth=bc_sb_request('auth/v1/signup','POST',$authBody,null,false);
 // The raw GoTrue /signup endpoint returns the user object at the JSON root;
 // SDKs wrap it as data.user, so accept either shape.
 $authUser=$auth['data']['user']??$auth['data'];
 if(is_array($authUser)&&array_key_exists('identities',$authUser)&&is_array($authUser['identities'])&&$authUser['identities']===[])throw new RuntimeException('Supabase returned an empty identity list for this email.');
 $uid=is_array($authUser)?(string)($authUser['id']??''):'';
 if($uid==='')throw new RuntimeException('Supabase Auth signup response did not contain a user id.');
 $fullName=trim($first.' '.$middle.' '.$last);$profile=['id'=>$uid,'role'=>'resident','status'=>'active','full_name'=>$fullName,'email'=>$email!==''?$email:null,'username'=>$username,'phone'=>$phone];
 $residentCode='BRGY-'.date('Y').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));
 $addr=trim(implode(', ',array_filter([trim((string)($_POST['house_street']??'')),trim((string)($_POST['barangay']??'')),trim((string)($_POST['city']??'')),trim((string)($_POST['province']??''))])));
 $row=['user_id'=>$uid,'resident_id'=>$residentCode,'first_name'=>$first,'middle_name'=>$middle,'last_name'=>$last,'suffix'=>$extra['suffix']??null,'birth_date'=>$birth,'gender'=>$gender,'address'=>$addr,'purok'=>$extra['purok']??null,'contact_number'=>$contact,'civil_status'=>trim((string)($_POST['civil_status']??'')),'username'=>$username,'email'=>$email?:null,'profile_data'=>$extra,'qr_token_hash'=>hash('sha256',$identity)];
 bc_sb_session_start();
 $_SESSION['bc_pending_registration']=['user_id'=>$uid,'email'=>$email,'profile'=>$profile,'resident'=>$row,'created_at'=>time()];
 echo json_encode(['success'=>true,'id'=>$uid,'resident_id'=>$residentCode,'email'=>$email,'message'=>'Registration started. Enter the six-digit verification code sent to your email to finish creating your account.','confirmation_required'=>true],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if($uid!==''){try{bc_sb_request('auth/v1/admin/users/'.rawurlencode($uid),'DELETE',null);}catch(Throwable $ignored){error_log('Supabase registration rollback failed for '.$uid);}}error_log('Supabase registration: '.$e->getMessage());http_response_code(503);echo json_encode(['success'=>false,'error'=>'Registration could not be completed. Check the Supabase configuration or submitted details.']);}
