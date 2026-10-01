<?php
declare(strict_types=1);
require_once __DIR__.'/supabase_client.php'; header('Content-Type: application/json; charset=utf-8');
function bc_reg_fail(int $code,string $message): never {http_response_code($code);echo json_encode(['success'=>false,'error'=>$message],JSON_UNESCAPED_UNICODE);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')bc_reg_fail(405,'Method not allowed');
$first=trim((string)($_POST['first_name']??''));$middle=trim((string)($_POST['middle_name']??''));$last=trim((string)($_POST['last_name']??''));$username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm_password']??'');$contact=preg_replace('/\D/','',(string)($_POST['contact_number']??''));$email=strtolower(trim((string)($_POST['email']??'')));$verificationMethod=(string)($_POST['verification_method']??'email');$birth=trim((string)($_POST['birthdate']??''));$gender=trim((string)($_POST['gender']??''));$extraRaw=(string)($_POST['profile_extra']??'');$extra=json_decode($extraRaw,true);if(!is_array($extra))$extra=[];
if($first===''||$last===''||$username===''||$password===''||$birth===''||$gender==='')bc_reg_fail(400,'Complete all required fields.');
if(strlen($password)<8)bc_reg_fail(400,'Password must be at least 8 characters.');if($password!==$confirm)bc_reg_fail(400,'Passwords do not match.');if(!preg_match('/^09\d{9}$/',$contact))bc_reg_fail(400,'Contact number must be 11 digits.');if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL))bc_reg_fail(400,'A valid email address is required for account contact.');
if(!in_array($verificationMethod,['email','sms'],true))bc_reg_fail(400,'Choose email or SMS verification.');
if($verificationMethod==='sms'&&getenv('BC_SUPABASE_SMS_OTP_ENABLED')!=='1')bc_reg_fail(400,'SMS verification is not enabled yet. Choose email or ask the administrator to configure an SMS provider.');
$uid='';
try{
 $phone='+63'.substr($contact,1);
 $filters=['or'=>'(username.eq.'.$username.',phone.eq.'.$phone.($email!==''?',email.eq.'.$email:'').')','select'=>'id','limit'=>'1'];
 $existing=bc_sb_rest('profiles','GET',$filters);if(!empty($existing['data']))bc_reg_fail(409,'This username, email, or mobile number is already registered. Sign in or use different details.');
 $identity=bin2hex(random_bytes(32));
 $authBody=['password'=>$password,'data'=>['username'=>$username,'first_name'=>$first,'middle_name'=>$middle,'last_name'=>$last,'phone'=>$phone,'contact_email'=>$email]];
 if($verificationMethod==='sms')$authBody['phone']=$phone;else $authBody['email']=$email;
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
 $target=$verificationMethod==='sms'?$phone:$email;
 $_SESSION['bc_pending_registration']=['user_id'=>$uid,'email'=>$email,'phone'=>$phone,'verification_method'=>$verificationMethod,'verification_target'=>$target,'profile'=>$profile,'resident'=>$row,'created_at'=>time()];
 echo json_encode(['success'=>true,'id'=>$uid,'resident_id'=>$residentCode,'email'=>$email,'phone'=>$phone,'verification_method'=>$verificationMethod,'verification_target'=>$target,'message'=>'Registration started. Enter the six-digit verification code sent to your selected contact.','confirmation_required'=>true],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($uid !== '') {
        try {
            bc_sb_request('auth/v1/admin/users/' . rawurlencode($uid), 'DELETE', null);
        } catch (Throwable $ignored) {
            error_log('Supabase registration rollback failed for ' . $uid);
        }
    }

    error_log('Supabase registration: ' . $e->getMessage());
    $status = in_array($e->getCode(), [400, 401, 409, 422, 429], true) ? $e->getCode() : 503;
    $lower = strtolower($e->getMessage());

    if ($status === 429 || str_contains($lower, 'rate limit')) {
        $message = 'Supabase is sending codes too quickly. Wait a few minutes and try again.';
    } elseif (str_contains($lower, 'already registered') || str_contains($lower, 'already been registered') || str_contains($lower, 'empty identity list')) {
        $message = 'This email or mobile number is already registered. Sign in or use a different contact.';
    } elseif (str_contains($lower, 'password')) {
        $message = 'Supabase rejected the password. Use a stronger password with at least 8 characters.';
    } elseif (str_contains($lower, 'missing server environment variable') || str_contains($lower, 'php curl extension')) {
        $message = 'The server is missing required Supabase settings or PHP cURL support. Contact the administrator.';
    } elseif (str_contains($lower, '535') && (str_contains($lower, 'gsmtp') || str_contains($lower, 'badcredentials') || str_contains($lower, 'username and password not accepted'))) {
        $message = 'Google rejected the SMTP credentials. Check the Gmail SMTP username and replace the SMTP password in Supabase with a valid Google App Password.';
    } elseif ($verificationMethod === 'sms') {
        $message = 'SMS registration could not send a code. Check Supabase phone authentication and SMS provider settings.';
    } elseif (str_contains($lower, 'email') || str_contains($lower, 'smtp') || str_contains($lower, 'mail')) {
        $message = 'Supabase could not send the verification email. Check the signup email template, SMTP settings, and sending limits.';
    } elseif ($status === 400 || $status === 409 || $status === 422) {
        $message = 'Supabase rejected these registration details. Check for an existing account and verify the email, mobile number, and password.';
    } else {
        $message = 'Registration could not reach Supabase Auth. Check the PHP server configuration and Supabase Auth settings.';
    }

    http_response_code($status);
    echo json_encode(['success' => false, 'code' => 'registration_failed', 'error' => $message], JSON_UNESCAPED_UNICODE);
}
