<?php
declare(strict_types=1);
require_once __DIR__.'/supabase_client.php';
header('Content-Type: application/json; charset=utf-8');
function bc_login_out(int $status,array $payload): never { http_response_code($status); echo json_encode($payload,JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD']!=='POST') bc_login_out(405,['success'=>false,'error'=>'Method not allowed']);
function bc_login_throttled(bool $reset=false): bool {
  $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$file=sys_get_temp_dir().DIRECTORY_SEPARATOR.'bc-login-'.hash('sha256',$ip).'.json';$fp=@fopen($file,'c+');if(!$fp)return false;flock($fp,LOCK_EX);$raw=stream_get_contents($fp);$state=json_decode($raw?:'{}',true)?:[];$now=time();if(($state['expires']??0)<$now)$state=['count'=>0,'expires'=>$now+900];if($reset){$state=['count'=>0,'expires'=>$now+900];}elseif(($state['count']??0)>=10){flock($fp,LOCK_UN);fclose($fp);return true;}else{$state['count']=($state['count']??0)+1;}rewind($fp);ftruncate($fp,0);fwrite($fp,json_encode($state));fflush($fp);flock($fp,LOCK_UN);fclose($fp);return false;
}
function bc_login_resident_metadata(array $authUser, string $email): ?array {
  $metadata=$authUser['user_metadata']??[];
  if(!is_array($metadata))return null;
  $first=trim((string)($metadata['first_name']??''));
  $last=trim((string)($metadata['last_name']??''));
  $username=trim((string)($metadata['username']??''));
  if($first===''||$last===''||$username==='')return null;
  $phone=(string)($metadata['phone']??'');
  $digits=preg_replace('/\\D/','',$phone)??'';
  $contact=$digits;
  if(str_starts_with($digits,'639'))$contact='0'.substr($digits,2);
  return ['first_name'=>$first,'middle_name'=>trim((string)($metadata['middle_name']??'')),'last_name'=>$last,'username'=>$username,'phone'=>$phone,'contact_number'=>$contact,'email'=>$email];
}
$identifier=trim((string)($_POST['identifier']??'')); $password=(string)($_POST['password']??''); $captchaToken=(string)($_POST['captcha-verification']??'');
if ($identifier==='' || $password==='') bc_login_out(400,['success'=>false,'code'=>'missing_credentials','error'=>'Enter your username/email and password.']);
bc_sb_session_start();
$expectedCaptchaHash=(string)($_SESSION['bc_login_captcha_token_hash']??'');
$expectedCaptchaContext=(string)($_SESSION['bc_login_captcha_context']??'');
$captchaVerifiedAt=(int)($_SESSION['bc_login_captcha_verified_at']??0);
unset($_SESSION['bc_login_captcha_token_hash'],$_SESSION['bc_login_captcha_context'],$_SESSION['bc_login_captcha_verified_at']);
$submittedCaptchaContext=hash('sha256',strtolower($identifier));
if ($captchaToken==='' || $expectedCaptchaHash==='' || $captchaVerifiedAt<time()-300 || !hash_equals($expectedCaptchaHash,hash('sha256',$captchaToken)) || !hash_equals($expectedCaptchaContext,$submittedCaptchaContext)) bc_login_out(400,['success'=>false,'code'=>'captcha_invalid','error'=>'CAPTCHA verification expired or is invalid. Enter the new code to continue.']);
if(bc_login_throttled()){http_response_code(429);echo json_encode(['success'=>false,'code'=>'rate_limited','error'=>'Too many login attempts. Try again in 15 minutes.']);exit;}
$authAttempted=false;
try {
  $email=$identifier;
  $phoneFallback='';
  if (filter_var($email,FILTER_VALIDATE_EMAIL)) {
    $lookup=bc_sb_rest('profiles','GET',['email'=>'eq.'.$email,'select'=>'id,email,phone','limit'=>'1']);
  } else {
    $lookup=bc_sb_rest('profiles','GET',['username'=>'eq.'.$identifier,'select'=>'id,email,phone','limit'=>'1']);
  }
  $loginProfile=$lookup['data'][0]??null;
  if(is_array($loginProfile)){
    $phoneFallback=(string)($loginProfile['phone']??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)&&!empty($loginProfile['email']))$email=(string)$loginProfile['email'];
  }
  if (!filter_var($email,FILTER_VALIDATE_EMAIL) && !$phoneFallback) {
    // A username may identify a phone-auth account; its phone number is used below.
    $lookup=bc_sb_rest('profiles','GET',['username'=>'eq.'.$identifier,'select'=>'id,email,phone','limit'=>'1']);
    $found=$lookup['data'][0]??null;
    if(is_array($found)){if(!empty($found['email']))$email=(string)$found['email'];if(!empty($found['phone']))$phoneFallback=(string)$found['phone'];}
  }
  if (!filter_var($email,FILTER_VALIDATE_EMAIL) && !$phoneFallback) bc_login_out(401,['success'=>false,'code'=>'invalid_credentials','error'=>'Incorrect username/email or password. Check your sign-in details and try again.']);
  $credentials=filter_var($email,FILTER_VALIDATE_EMAIL)?['email'=>$email,'password'=>$password]:['phone'=>$phoneFallback,'password'=>$password];
  $authAttempted=true;
  try {
    $auth=bc_sb_request('auth/v1/token?grant_type=password','POST',$credentials,null,false);
  } catch (Throwable $authError) {
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || $phoneFallback==='' || $phoneFallback===($credentials['phone']??'')) throw $authError;
    try {
      $auth=bc_sb_request('auth/v1/token?grant_type=password','POST',['phone'=>$phoneFallback,'password'=>$password],null,false);
    } catch (Throwable $phoneAuthError) {
      throw $authError;
    }
  }
  $authData=$auth['data']; $authUser=is_array($authData['user']??null)?$authData['user']:[]; $userId=(string)($authUser['id']??'');
  if ($userId==='') bc_login_out(401,['success'=>false,'code'=>'invalid_credentials','error'=>'Incorrect username/email or password. Check your sign-in details and try again.']);
  $r=bc_sb_rest('profiles','GET',['id'=>'eq.'.$userId,'select'=>'id,role,status,full_name,email,phone,username','limit'=>'1']);
  $profile=$r['data'][0]??null;
  $residentData=bc_login_resident_metadata($authUser,$email);
  if (!$profile && $residentData) {
    $fullName=trim($residentData['first_name'].' '.$residentData['middle_name'].' '.$residentData['last_name']);
    $profile=['id'=>$userId,'role'=>'resident','status'=>'active','full_name'=>$fullName,'email'=>$email,'phone'=>$residentData['phone'],'username'=>$residentData['username']];
    bc_sb_rest('profiles','POST',[],$profile,['return=minimal']);
  }
  if (!$profile || !in_array(($profile['role']??''),['superadmin','admin','resident'],true) || ($profile['status']??'')!=='active') bc_login_out(403,['success'=>false,'error'=>'The email and password are valid, but this account is not linked to an active BarangayConnect profile. Contact the barangay administrator.']);
  $resident=null;
  if ($profile['role']==='resident') {
    $rr=bc_sb_rest('residents','GET',['user_id'=>'eq.'.$userId,'select'=>'id,resident_id,first_name,last_name','limit'=>'1']);
    $resident=$rr['data'][0]??null;
    if (!$resident && $residentData) {
      $residentCode='BRGY-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(4)));
      $residentRow=['user_id'=>$userId,'resident_id'=>$residentCode,'first_name'=>$residentData['first_name'],'middle_name'=>$residentData['middle_name']?:null,'last_name'=>$residentData['last_name'],'username'=>$residentData['username'],'email'=>$email,'contact_number'=>$residentData['contact_number'],'qr_token_hash'=>hash('sha256',random_bytes(32))];
      $createdResident=bc_sb_rest('residents','POST',[],$residentRow,['return=representation']);
      $resident=$createdResident['data'][0]??null;
      if (!is_array($resident) || empty($resident['id'])) bc_login_out(503,['success'=>false,'error'=>'Your resident record could not be linked. Please try again or contact the barangay administrator.']);
    }
    if (!$resident) bc_login_out(403,['success'=>false,'error'=>'The email and password are valid, but the resident record is incomplete. Contact the barangay administrator.']);
  }
  bc_sb_session_start(); session_regenerate_id(true);
  $_SESSION['bc_supabase']=['access_token'=>$authData['access_token'],'refresh_token'=>$authData['refresh_token']??null,'expires_at'=>time()+(int)($authData['expires_in']??3600),'user_id'=>$userId,'role'=>$profile['role'],'email'=>$profile['email']??$email,'full_name'=>$profile['full_name']??'BarangayConnect user','username'=>$profile['username']??'','resident'=>$resident];
  bc_login_throttled(true);
  bc_login_out(200,['success'=>true,'role'=>$profile['role'],'id'=>$userId,'residentId'=>$resident['resident_id']??'','firstname'=>$resident['first_name']??'','lastname'=>$resident['last_name']??'','username'=>$profile['username']??'','fullName'=>$profile['full_name']??'BarangayConnect user','privacyAcknowledged'=>false]);
} catch (Throwable $e) {
  error_log('BarangayConnect Supabase login: '.$e->getMessage());
  if($authAttempted && in_array($e->getCode(),[400,401],true))bc_login_out(401,['success'=>false,'code'=>'invalid_credentials','error'=>'Incorrect username/email or password. Check your sign-in details and try again.']);
  bc_login_out(503,['success'=>false,'code'=>'login_unavailable','error'=>'Login service is unavailable. Check the Supabase server configuration.']);
}

